<?php

namespace BoardDocsScraper\Console;

use BoardDocsScraper\Ai\VectorStoreSync;
use BoardDocsScraper\BoardDocsManager;
use BoardDocsScraper\Support\OutputPaths;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Scans a public BoardDocs site and exports self-contained meeting PDFs plus the
 * JSONL search index. Mirrors export_scope() from the original Python exporter.
 */
class ScanCommand extends Command
{
    protected $signature = 'boarddocs:scan
        {--site= : BoardDocs site slug (defaults to config, e.g. pa/phoe)}
        {--committee=* : Restrict to these committee ids}
        {--since= : Only meetings on/after this date (YYYY-MM-DD)}
        {--until= : Only meetings on/before this date (YYYY-MM-DD)}
        {--limit= : Maximum meetings per committee}
        {--no-attachments : Do not download or merge attachments}
        {--engine= : Override the PDF engine (tcpdf|browsershot)}
        {--refresh-recent-days= : Re-export existing PDFs for meetings within N days}
        {--fresh : Bypass the committee/meeting cache}
        {--memory-limit= : Raise PHP memory_limit for this run (e.g. 512M, 1G, -1 for unlimited)}
        {--dry-run : List what would be exported without writing anything}';

    protected $description = 'Scan a public BoardDocs site and export meeting PDFs + search index.';

    public function handle(BoardDocsManager $manager): int
    {
        $config = $manager->config();

        $this->applyMemoryLimit(
            $this->option('memory-limit') ?? ($config['scan']['memory_limit'] ?? null)
        );

        $siteName = $this->option('site') ?: $config['site'];

        $overrides = [];
        if ($engine = $this->option('engine')) {
            $overrides['pdf']['engine'] = $engine;
        }
        if ($this->option('no-attachments')) {
            $overrides['pdf']['self_contained'] = false;
        }
        if ($this->option('fresh')) {
            $overrides['cache']['enabled'] = false;
        }

        $site = $manager->site($siteName, $overrides);
        $disk = Storage::disk($config['output']['disk'] ?? 'local');
        $refreshDays = (int) ($this->option('refresh-recent-days') ?? $config['scan']['refresh_recent_days'] ?? 30);

        $committeeIds = array_map('strtolower', (array) $this->option('committee'));
        $since = $this->option('since');
        $until = $this->option('until');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $committees = $site->committees();
        if (! empty($committeeIds)) {
            $committees = $committees->filter(
                fn ($c) => in_array(strtolower($c->committeeId), $committeeIds, true)
            )->values();
        }

        if ($committees->isEmpty()) {
            $this->warn("No committees found for site '{$siteName}'.");

            return self::FAILURE;
        }

        $index = $manager->indexBuilder()->load();
        $vectorSync = new VectorStoreSync($config);
        $vectorSync = $vectorSync->enabled() ? $vectorSync : null;
        $written = $skipped = $failed = $vectorSynced = 0;

        foreach ($committees as $committee) {
            $this->info("Committee: {$committee->name} ({$committee->committeeId})");

            $meetings = $committee->meetings();
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
                    if ($vectorSync !== null && ! $this->option('dry-run')) {
                        $existing = $index->get(OutputPaths::relativeToBase($config, $rel));
                        if ($existing !== null && empty($existing['vector_document_id'])) {
                            $index->put($vectorSync->sync($existing, $rel));
                            $vectorSynced++;
                        }
                    }

                    $this->line("  skip existing {$meeting->date()}");
                    $skipped++;

                    continue;
                }

                if ($this->option('dry-run')) {
                    $this->line("  would export {$meeting->date()} — ".Str::limit($meeting->name(), 60));

                    continue;
                }

                try {
                    $agenda = $meeting->agenda();
                    if ($this->option('no-attachments')) {
                        $agenda->withoutAttachments();
                    }

                    $pdf = $agenda->toPdf();
                    $pdf->save($rel);

                    $entry = $pdf->indexEntry($rel);

                    if ($vectorSync !== null) {
                        $entry = $vectorSync->sync($entry, $rel, $index->get($entry['path']));
                        $vectorSynced++;
                    }

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
        $this->info($vectorSync !== null
            ? "Done. wrote={$written} skipped={$skipped} failed={$failed} vector_synced={$vectorSynced}"
            : "Done. wrote={$written} skipped={$skipped} failed={$failed}");

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
     * Raise PHP's memory_limit for this scan. PDF assembly holds every
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
