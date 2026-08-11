<?php

namespace BoardDocsScraper\Console;

use BoardDocsScraper\BoardDocsManager;
use BoardDocsScraper\Support\Urls;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Wipes what `boarddocs:build` produced — the merged/rewritten meeting PDFs
 * and their entries in index.jsonl — so the next build regenerates every
 * meeting instead of skipping ones that already exist (e.g. after switching
 * PDF templates). Never touches the archived source material (see
 * `boarddocs:clear-archive` for that); `boarddocs:build` can regenerate
 * everything straight back from the archive.
 */
class ClearOutputCommand extends Command
{
    protected $signature = 'boarddocs:clear-output
        {--site= : BoardDocs site slug (defaults to config, e.g. pa/phoe)}
        {--all : Clear generated PDFs and the whole index, not just one site}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Delete generated meeting PDFs (and their index entries) so the next boarddocs:build regenerates everything.';

    public function handle(BoardDocsManager $manager): int
    {
        $config = $manager->config();
        $disk = Storage::disk($config['output']['disk'] ?? 'local');
        $base = trim((string) ($config['output']['path'] ?? 'boarddocs-private'), '/');

        $all = (bool) $this->option('all');
        $district = Urls::districtIdFromSite($this->option('site') ?: $config['site']);
        $path = $all ? $base : trim($base.'/'.$district, '/');

        if (! $disk->exists($path)) {
            $this->info($all
                ? "Output is already empty ({$path})."
                : "Nothing built at {$path} — already clear.");

            return self::SUCCESS;
        }

        $label = $all ? "every generated PDF and the whole index ({$path})" : "the generated PDFs and index entries for {$path}";

        if (! $this->option('force') && ! $this->confirm("This will permanently delete {$label}. Continue?")) {
            $this->warn('Aborted — nothing was deleted.');

            return self::FAILURE;
        }

        $index = $manager->indexBuilder()->load();
        $removed = $all ? $index->count() : $index->forgetDistrict($district);
        if ($all) {
            $index->clear();
        }

        $disk->deleteDirectory($path);
        $index->save();

        $this->info("Deleted {$label} ({$removed} index entries removed).");

        return self::SUCCESS;
    }
}
