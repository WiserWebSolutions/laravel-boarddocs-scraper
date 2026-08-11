<?php

namespace BoardDocsScraper\Console;

use BoardDocsScraper\Ai\VectorStoreSync;
use BoardDocsScraper\BoardDocsManager;
use BoardDocsScraper\Data\CommitteeData;
use BoardDocsScraper\Data\MeetingData;
use BoardDocsScraper\Resources\Committee;
use BoardDocsScraper\Resources\Meeting;
use BoardDocsScraper\Resources\Site;
use BoardDocsScraper\Support\Archive;
use BoardDocsScraper\Support\OutputPaths;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Uploads what `boarddocs:build` has already produced into the configured
 * vector store: the merged agenda PDF, the raw print-agenda HTML, and every
 * raw attachment file. Each document is tagged with the specific meeting (and,
 * for attachments, the specific file within it) it belongs to, so a FileSearch
 * agent can cite exactly which agenda or attachment it drew from.
 *
 * Runs as its own step, after `boarddocs:build`/`boarddocs:scan`, rather than
 * inline during build — a slow or failing upload to the vector store's API
 * (e.g. Gemini's fileSearchStores) then never interrupts PDF generation.
 * Idempotent: each uploaded document's id is recorded on the meeting's index
 * entry, and re-runs only touch files that don't have one yet (or all files,
 * with --force).
 */
class SyncVectorCommand extends Command
{
    protected $signature = 'boarddocs:sync-vector
        {--site= : BoardDocs site slug (defaults to config, e.g. pa/phoe)}
        {--committee=* : Restrict to these committee ids}
        {--since= : Only meetings on/after this date (YYYY-MM-DD)}
        {--until= : Only meetings on/before this date (YYYY-MM-DD)}
        {--limit= : Maximum meetings per committee}
        {--force : Re-upload files that already have a vector document id}
        {--dry-run : List what would be uploaded without contacting the vector store}';

    protected $description = 'Upload built agenda PDFs and raw archive files (agenda HTML + attachments) into the configured vector store.';

    public function handle(BoardDocsManager $manager): int
    {
        $config = $manager->config();

        $vectorSync = new VectorStoreSync($config);
        if (! $vectorSync->enabled()) {
            $this->error('Vector sync is not enabled — set boarddocs.ai.search_driver to "vector" and configure boarddocs.ai.vector_store.id (see boarddocs:vector-store:create).');

            return self::FAILURE;
        }

        $siteName = $this->option('site') ?: $config['site'];
        $client = $manager->client($siteName);
        $site = new Site($client, $config, $siteName);
        $archive = new Archive($config);

        $outputDisk = $config['output']['disk'] ?? 'local';
        $archiveDisk = $config['archive']['disk'] ?? $outputDisk;
        $disk = Storage::disk($outputDisk);
        $archiveStorage = Storage::disk($archiveDisk);

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

        $since = $this->option('since');
        $until = $this->option('until');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        $index = $manager->indexBuilder()->load();
        $uploaded = $skipped = $failed = 0;

        foreach ($committeeRows as $row) {
            $committeeData = CommitteeData::fromArray($row);
            $committee = new Committee($site, $client, $config, $committeeData->committeeId, $committeeData->name);

            $this->info("Committee: {$committee->name} ({$committee->committeeId})");

            $meetingRows = $archive->getMeetings($siteName, $committee->name);
            if ($meetingRows === null) {
                $this->line('  no meetings archived for this committee.');

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
                $date = $meeting->date();
                $meetingPath = OutputPaths::meetingPath($config, $siteName, $committee->name, $date);
                $indexPath = OutputPaths::relativeToBase($config, $meetingPath);
                $entry = $index->get($indexPath);

                if ($entry === null) {
                    $this->line("  skip {$date} — not built yet, run boarddocs:build first.");
                    $skipped++;

                    continue;
                }

                $meta = [
                    'path' => $indexPath,
                    'district' => $entry['district'] ?? null,
                    'committee' => $committee->name,
                    'date' => $date,
                ];

                [$entry, $ok, $bad] = $this->syncAgendaPdf(
                    $vectorSync, $entry, $meetingPath, $outputDisk, $disk, $meta, $force, $dryRun, $date,
                );
                $uploaded += $ok;
                $failed += $bad;

                [$entry, $ok, $bad] = $this->syncAgendaHtml(
                    $vectorSync, $entry, $config, $siteName, $committee->name, $date, $archiveDisk, $archiveStorage, $meta, $force, $dryRun,
                );
                $uploaded += $ok;
                $failed += $bad;

                [$entry, $ok, $bad] = $this->syncAttachments(
                    $vectorSync, $entry, $archive, $config, $siteName, $committee->name, $date, $archiveDisk, $meta, $force, $dryRun,
                );
                $uploaded += $ok;
                $failed += $bad;

                if (! $dryRun) {
                    $index->put($entry);
                }
            }
        }

        if (! $dryRun) {
            $path = $index->save();
            $this->info("Index written: {$path}");
        }

        $this->newLine();
        $this->info("Done. uploaded={$uploaded} skipped={$skipped} failed={$failed}");

        return self::SUCCESS;
    }

    /**
     * @return array{0: array, 1: int, 2: int} [entry, uploaded, failed]
     */
    protected function syncAgendaPdf(VectorStoreSync $vectorSync, array $entry, string $meetingPath, string $outputDisk, $disk, array $meta, bool $force, bool $dryRun, string $date): array
    {
        if (! $disk->exists($meetingPath) || (! $force && ! empty($entry['vector_document_id']))) {
            return [$entry, 0, 0];
        }

        if ($dryRun) {
            $this->line("  would upload {$date} agenda PDF");

            return [$entry, 0, 0];
        }

        try {
            $id = $vectorSync->syncDocument($meetingPath, $outputDisk, array_merge($meta, [
                'kind' => 'agenda_pdf',
                'page_count' => $entry['page_count'] ?? null,
            ]), $entry['vector_document_id'] ?? null);

            $entry['vector_document_id'] = $id;
            $this->info("  uploaded {$date} agenda PDF");

            return [$entry, 1, 0];
        } catch (\Throwable $e) {
            $this->error("  failed {$date} agenda PDF: ".$e->getMessage());

            return [$entry, 0, 1];
        }
    }

    /**
     * @return array{0: array, 1: int, 2: int} [entry, uploaded, failed]
     */
    protected function syncAgendaHtml(VectorStoreSync $vectorSync, array $entry, array $config, string $siteName, string $committeeName, string $date, string $archiveDisk, $archiveStorage, array $meta, bool $force, bool $dryRun): array
    {
        $htmlPath = OutputPaths::archiveAgendaHtmlPath($config, $siteName, $committeeName, $date);

        if (! $archiveStorage->exists($htmlPath) || (! $force && ! empty($entry['vector_agenda_html_id']))) {
            return [$entry, 0, 0];
        }

        if ($dryRun) {
            $this->line("  would upload {$date} raw agenda HTML");

            return [$entry, 0, 0];
        }

        try {
            $id = $vectorSync->syncDocument($htmlPath, $archiveDisk, array_merge($meta, [
                'kind' => 'agenda_html',
            ]), $entry['vector_agenda_html_id'] ?? null);

            $entry['vector_agenda_html_id'] = $id;
            $this->info("  uploaded {$date} raw agenda HTML");

            return [$entry, 1, 0];
        } catch (\Throwable $e) {
            $this->error("  failed {$date} raw agenda HTML: ".$e->getMessage());

            return [$entry, 0, 1];
        }
    }

    /**
     * @return array{0: array, 1: int, 2: int} [entry, uploaded, failed]
     */
    protected function syncAttachments(VectorStoreSync $vectorSync, array $entry, Archive $archive, array $config, string $siteName, string $committeeName, string $date, string $archiveDisk, array $meta, bool $force, bool $dryRun): array
    {
        $manifest = $archive->getManifest($siteName, $committeeName, $date) ?? [];
        $attachmentIds = $entry['vector_attachment_ids'] ?? [];
        $uploaded = $failed = 0;

        foreach ($manifest as $record) {
            $bookmark = (string) ($record['bookmark'] ?? '');
            if ($bookmark === '' || ! $archive->hasAttachmentFile($siteName, $committeeName, $date, $bookmark)) {
                continue;
            }

            if (! $force && ! empty($attachmentIds[$bookmark])) {
                continue;
            }

            if ($dryRun) {
                $this->line("  would upload {$date} attachment: {$bookmark}");

                continue;
            }

            $attachmentPath = OutputPaths::archiveAttachmentsPath($config, $siteName, $committeeName, $date).'/'.$bookmark;

            try {
                $id = $vectorSync->syncDocument($attachmentPath, $archiveDisk, array_merge($meta, [
                    'kind' => 'attachment',
                    'title' => $bookmark,
                    'bookmark' => $bookmark,
                    'item_unique' => $record['itemUnique'] ?? null,
                    'file_unique' => $record['fileUnique'] ?? null,
                    'href' => $record['href'] ?? null,
                ]), $attachmentIds[$bookmark] ?? null);

                $attachmentIds[$bookmark] = $id;
                $uploaded++;
                $this->info("  uploaded {$date} attachment: {$bookmark}");
            } catch (\Throwable $e) {
                $failed++;
                $this->error("  failed {$date} attachment {$bookmark}: ".$e->getMessage());
            }
        }

        if ($uploaded > 0) {
            $entry['vector_attachment_ids'] = $attachmentIds;
        }

        return [$entry, $uploaded, $failed];
    }
}
