<?php

namespace BoardDocsScraper\Console;

use BoardDocsScraper\BoardDocsManager;
use BoardDocsScraper\Data\CommitteeData;
use BoardDocsScraper\Data\MeetingData;
use BoardDocsScraper\Resources\Committee;
use BoardDocsScraper\Resources\Meeting;
use BoardDocsScraper\Resources\Site;
use BoardDocsScraper\Support\Archive;
use BoardDocsScraper\Support\OutputPaths;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Builds meeting PDFs plus the JSONL search index strictly from what
 * `boarddocs:prefetch` has already put in the archive — committee list,
 * meeting lists, agenda HTML, and attachment files. Makes NO BoardDocs
 * request of its own: if a meeting's agenda HTML, attachment manifest, or an
 * individual attachment file is missing from the archive, that meeting is
 * skipped with a clear message rather than falling back to a live request.
 * Run `boarddocs:prefetch` (or `boarddocs:scan`, which runs both) first to
 * fill any gap.
 */
class BuildCommand extends Command
{
    protected $signature = 'boarddocs:build
        {--site= : BoardDocs site slug (defaults to config, e.g. pa/phoe)}
        {--committee=* : Restrict to these committee ids}
        {--since= : Only meetings on/after this date (YYYY-MM-DD)}
        {--until= : Only meetings on/before this date (YYYY-MM-DD)}
        {--limit= : Maximum meetings per committee}
        {--no-attachments : Do not merge attachments (agenda-only PDFs)}
        {--engine= : Override the PDF engine (tcpdf|browsershot)}
        {--refresh-recent-days= : Re-build existing PDFs for meetings within N days}
        {--memory-limit= : Raise PHP memory_limit for this run (e.g. 512M, 1G, -1 for unlimited)}
        {--dry-run : List what would be built without writing anything}';

    protected $description = 'Build meeting PDFs + search index from the archive, without making any BoardDocs request.';

    public function handle(BoardDocsManager $manager): int
    {
        $config = $manager->config();

        $this->applyMemoryLimit(
            $this->option('memory-limit') ?? ($config['scan']['memory_limit'] ?? null)
        );

        if (! ($config['archive']['enabled'] ?? true)) {
            $this->error('boarddocs.archive.enabled is false — there is nothing to build from.');

            return self::FAILURE;
        }

        $siteName = $this->option('site') ?: $config['site'];

        $overrides = [];
        if ($engine = $this->option('engine')) {
            $overrides['pdf']['engine'] = $engine;
        }
        if ($this->option('no-attachments')) {
            $overrides['pdf']['self_contained'] = false;
        }

        $config = empty($overrides) ? $config : array_replace_recursive($config, $overrides);
        $client = $manager->client($siteName, $overrides);
        $site = new Site($client, $config, $siteName);
        $archive = new Archive($config);

        $disk = Storage::disk($config['output']['disk'] ?? 'local');
        $refreshDays = (int) ($this->option('refresh-recent-days') ?? $config['scan']['refresh_recent_days'] ?? 30);

        $committeeRows = $archive->getCommittees($siteName);
        if ($committeeRows === null) {
            $this->warn("Nothing archived yet for site '{$siteName}' — run boarddocs:prefetch first.");

            // A dry run just reports what's buildable; having nothing archived
            // yet is informational, not a failure, for that case.
            return $this->option('dry-run') ? self::SUCCESS : self::FAILURE;
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

            return $this->option('dry-run') ? self::SUCCESS : self::FAILURE;
        }

        $since = $this->option('since');
        $until = $this->option('until');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $index = $manager->indexBuilder()->load();
        $written = $skipped = $failed = 0;

        foreach ($committeeRows as $row) {
            $committeeData = CommitteeData::fromArray($row);
            $committee = new Committee($site, $client, $config, $committeeData->committeeId, $committeeData->name);

            $this->info("Committee: {$committee->name} ({$committee->committeeId})");

            $meetingRows = $archive->getMeetings($siteName, $committee->name);
            if ($meetingRows === null) {
                $this->line('  no meetings archived for this committee — run boarddocs:prefetch first.');

                continue;
            }

            $meetings = collect($meetingRows)
                ->map(fn ($m) => new Meeting($committee, $client, $config, MeetingData::fromArray($m)))
                ->values();

            if ($since) {
                $needle = str_replace('-', '', $since);
                $meetings = $meetings->filter(fn ($m) => $m->numberdate() >= $needle);
            }
            if ($until) {
                $needle = str_replace('-', '', $until);
                $meetings = $meetings->filter(fn ($m) => $m->numberdate() <= $needle);
            }
            if ($limit !== null) {
                $meetings = $meetings->take($limit);
            }

            foreach ($meetings as $meeting) {
                $rel = OutputPaths::meetingPath($config, $siteName, $committee->name, $meeting->date());

                if ($disk->exists($rel) && ! $this->withinRecent($meeting->date(), $refreshDays)) {
                    $this->line("  skip existing {$meeting->date()}");
                    $skipped++;

                    continue;
                }

                if ($this->option('dry-run')) {
                    $this->line("  would build {$meeting->date()} — ".Str::limit($meeting->name(), 60));

                    continue;
                }

                try {
                    $agenda = $meeting->agenda();
                    if ($this->option('no-attachments')) {
                        $agenda->withoutAttachments();
                    }

                    $pdf = $agenda->toPdfFromArchive();
                    $pdf->save($rel);

                    $entry = $pdf->indexEntry($rel);

                    $index->put($entry);
                    $written++;

                    $this->info(sprintf(
                        '  wrote %s (%d pages, %d attachments)',
                        $rel,
                        $pdf->pageCount(),
                        count($pdf->attachments()),
                    ));
                } catch (\Throwable $e) {
                    $failed++;
                    $this->error("  failed {$meeting->date()}: ".$e->getMessage());
                }
            }
        }

        if (! $this->option('dry-run')) {
            $path = $index->save();
            $this->info("Index written: {$path} ({$index->count()} meetings)");
        }

        $this->newLine();
        $this->info("Done. wrote={$written} skipped={$skipped} failed={$failed}");

        return self::SUCCESS;
    }

    protected function withinRecent(string $isoDate, int $days): bool
    {
        if ($days <= 0) {
            return false;
        }

        try {
            return Carbon::parse($isoDate)->gte(Carbon::today()->subDays($days));
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Raise PHP's memory_limit for this build. PDF assembly holds every
     * attachment's bytes in memory while TCPDF/FPDI buffer the merged document,
     * so the default 128M is easily exhausted. Only ever raises the ceiling so
     * an already-generous environment limit is never reduced.
     */
    protected function applyMemoryLimit(?string $limit): void
    {
        $limit = $limit !== null ? trim($limit) : '';
        if ($limit === '') {
            return;
        }

        $target = $this->memoryLimitToBytes($limit);
        $current = $this->memoryLimitToBytes((string) ini_get('memory_limit'));

        // -1 is unlimited (treated as the largest value); never lower the limit.
        if ($target !== -1 && $current === -1) {
            return;
        }
        if ($target !== -1 && $current !== -1 && $target <= $current) {
            return;
        }

        ini_set('memory_limit', $limit);
    }

    /**
     * Convert an ini memory shorthand ("512M", "1G", "-1") into a byte count.
     * Returns -1 for the unlimited sentinel.
     */
    protected function memoryLimitToBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return $value === '-1' ? -1 : 0;
        }

        $number = (int) $value;
        $unit = strtolower(substr($value, -1));

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => (int) $value,
        };
    }
}
