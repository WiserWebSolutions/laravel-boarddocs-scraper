<?php

namespace BoardDocsScraper\Console;

use BoardDocsScraper\BoardDocsManager;
use Illuminate\Console\Command;

/**
 * Search the exported index.jsonl from the command line.
 */
class SearchCommand extends Command
{
    protected $signature = 'boarddocs:search
        {query* : One or more search terms}
        {--committee= : Restrict to a committee (name contains)}
        {--limit=20 : Maximum results}
        {--json : Output raw JSON}';

    protected $description = 'Search exported BoardDocs agendas (index.jsonl).';

    public function handle(BoardDocsManager $manager): int
    {
        $query = implode(' ', (array) $this->argument('query'));

        $results = $manager->searcher()->search(
            $query,
            (int) $this->option('limit'),
            $this->option('committee'),
        );

        if ($this->option('json')) {
            $this->line(json_encode($results->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($results->isEmpty()) {
            $this->warn("No matches for: {$query}");

            return self::SUCCESS;
        }

        foreach ($results as $r) {
            $this->info(sprintf(
                '%s — %s  (score %d, %d pages)',
                $r['date'] ?? '?',
                $r['committee'] ?? '?',
                $r['score'] ?? 0,
                $r['page_count'] ?? 0,
            ));
            $this->line('  '.($r['path'] ?? ''));
            if (! empty($r['snippet'])) {
                $this->line('  '.$r['snippet']);
            }
            $this->newLine();
        }

        return self::SUCCESS;
    }
}
