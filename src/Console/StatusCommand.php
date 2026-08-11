<?php

namespace BoardDocsScraper\Console;

use BoardDocsScraper\BoardDocsManager;
use BoardDocsScraper\Data\MeetingData;
use BoardDocsScraper\Support\Archive;
use BoardDocsScraper\Support\OutputPaths;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Summarizes what `boarddocs:prefetch` has archived and how much of it
 * `boarddocs:build` has already compiled, purely by inspecting the archive
 * and output disks — makes no BoardDocs request of its own.
 */
class StatusCommand extends Command
{
    protected $signature = 'boarddocs:status
        {--site= : BoardDocs site slug (defaults to config, e.g. pa/phoe)}
        {--committee=* : Restrict to these committee ids}';

    protected $description = 'Summarize archived vs. compiled meetings without making any BoardDocs request.';

    public function handle(BoardDocsManager $manager): int
    {
        $config = $manager->config();
        $siteName = $this->option('site') ?: $config['site'];

        $archive = new Archive($config);
        $disk = Storage::disk($config['output']['disk'] ?? 'local');

        $committeeRows = $archive->getCommittees($siteName);
        if ($committeeRows === null) {
            $this->warn("Nothing archived yet for site '{$siteName}' — run boarddocs:prefetch first.");

            return self::FAILURE;
        }

        $committeeIds = array_map('strtolower', (array) $this->option('committee'));
        if (! empty($committeeIds)) {
            $committeeRows = array_values(array_filter(
                $committeeRows,
                fn ($row) => in_array(strtolower((string) ($row['committee_id'] ?? '')), $committeeIds, true)
            ));
        }

        if (empty($committeeRows)) {
            $this->warn("No archived committees found for site '{$siteName}'.");

            return self::FAILURE;
        }

        $totalCached = $totalCompiled = $totalCompilable = 0;

        foreach ($committeeRows as $row) {
            $committeeName = (string) ($row['name'] ?? '');
            $meetingRows = $archive->getMeetings($siteName, $committeeName);

            $this->info("Committee: {$committeeName} (".($row['committee_id'] ?? '').')');

            if ($meetingRows === null) {
                $this->line('  no meetings archived for this committee');

                continue;
            }

            $cached = $compiled = $compilable = 0;

            foreach ($meetingRows as $meetingRow) {
                $date = MeetingData::fromArray($meetingRow)->isoDate();

                $isCached = $archive->hasAgendaHtml($siteName, $committeeName, $date);
                $isCompiled = $disk->exists(OutputPaths::meetingPath($config, $siteName, $committeeName, $date));

                if ($isCached) {
                    $cached++;
                }
                if ($isCompiled) {
                    $compiled++;
                }
                if ($isCached && ! $isCompiled) {
                    $compilable++;
                }
            }

            $this->line("  {$cached} cached, {$compiled} compiled, {$compilable} additional able to be compiled");

            $totalCached += $cached;
            $totalCompiled += $compiled;
            $totalCompilable += $compilable;
        }

        $this->newLine();
        $this->info("{$totalCached} cached meetings found, {$totalCompiled} meetings compiled, {$totalCompilable} additional meetings able to be compiled.");

        if ($totalCompilable > 0) {
            $this->info('Run boarddocs:build to compile meetings into single files.');
        }

        return self::SUCCESS;
    }
}
