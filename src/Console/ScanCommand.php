<?php

namespace BoardDocsScraper\Console;

use Illuminate\Console\Command;

/**
 * Scans a public BoardDocs site end-to-end: runs `boarddocs:prefetch` (fetch
 * agenda HTML + attachments into the archive) followed by `boarddocs:build`
 * (render PDFs + the JSONL search index from the archive). Equivalent to
 * running both yourself, but as one command for the common case where
 * nothing is currently blocking you.
 *
 * If prefetch gets blocked partway through (BoardDocs returning 403), the
 * build step still runs and produces PDFs for whatever was already
 * archived — from this run or an earlier one.
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
        {--refresh-recent-days= : Re-build existing PDFs for meetings within N days}
        {--fresh : Bypass the committee/meeting cache during prefetch}
        {--memory-limit= : Raise PHP memory_limit for the build step (e.g. 512M, 1G, -1 for unlimited)}
        {--dry-run : List what would be built without prefetching or writing anything}';

    protected $description = 'Scan a public BoardDocs site: prefetch into the archive, then build meeting PDFs + search index.';

    public function handle(): int
    {
        $shared = [];
        if ($site = $this->option('site')) {
            $shared['--site'] = $site;
        }
        if ($committees = $this->option('committee')) {
            $shared['--committee'] = $committees;
        }
        if ($since = $this->option('since')) {
            $shared['--since'] = $since;
        }
        if ($until = $this->option('until')) {
            $shared['--until'] = $until;
        }
        if ($limit = $this->option('limit')) {
            $shared['--limit'] = $limit;
        }
        if ($this->option('no-attachments')) {
            $shared['--no-attachments'] = true;
        }

        // A dry run reports what would be built without making any BoardDocs
        // request of its own, so prefetching (which only ever makes live
        // requests) is skipped entirely rather than passed --dry-run itself.
        if (! $this->option('dry-run')) {
            $prefetchOptions = $shared;
            if ($this->option('fresh')) {
                $prefetchOptions['--fresh'] = true;
            }
            // So a recent meeting whose archive was lost still gets
            // refreshed before build tries to rebuild it under the same
            // --refresh-recent-days window.
            if ($refreshDays = $this->option('refresh-recent-days')) {
                $prefetchOptions['--refresh-recent-days'] = $refreshDays;
            }

            if ($this->call('boarddocs:prefetch', $prefetchOptions) !== self::SUCCESS) {
                $this->warn('boarddocs:prefetch stopped early — continuing to build whatever is already archived.');
            }

            $this->newLine();
        }

        $buildOptions = $shared;
        if ($engine = $this->option('engine')) {
            $buildOptions['--engine'] = $engine;
        }
        if ($refreshDays = $this->option('refresh-recent-days')) {
            $buildOptions['--refresh-recent-days'] = $refreshDays;
        }
        if ($memoryLimit = $this->option('memory-limit')) {
            $buildOptions['--memory-limit'] = $memoryLimit;
        }
        if ($this->option('dry-run')) {
            $buildOptions['--dry-run'] = true;
        }

        return $this->call('boarddocs:build', $buildOptions);
    }
}
