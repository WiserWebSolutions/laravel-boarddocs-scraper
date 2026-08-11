<?php

namespace BoardDocsScraper\Console;

use BoardDocsScraper\BoardDocsManager;
use BoardDocsScraper\Support\Urls;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Wipes the archived material `boarddocs:prefetch` downloaded — raw
 * print-agenda HTML, attachment files, and the cached committee/meeting lists
 * — so the next prefetch starts from scratch instead of skipping what's
 * already there. Never touches the generated PDFs or index (see
 * `boarddocs:clear-output` for that).
 */
class ClearArchiveCommand extends Command
{
    protected $signature = 'boarddocs:clear-archive
        {--site= : BoardDocs site slug (defaults to config, e.g. pa/phoe)}
        {--all : Clear archived data for every site, not just one}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Delete archived agenda HTML/attachments so the next boarddocs:prefetch starts from scratch.';

    public function handle(BoardDocsManager $manager): int
    {
        $config = $manager->config();
        $disk = Storage::disk($config['archive']['disk'] ?? ($config['output']['disk'] ?? 'local'));
        $base = trim((string) ($config['archive']['path'] ?? 'boarddocs-public'), '/');

        $all = (bool) $this->option('all');
        $path = $all ? $base : trim($base.'/'.Urls::districtIdFromSite($this->option('site') ?: $config['site']), '/');

        if (! $disk->exists($path)) {
            $this->info($all
                ? "Archive is already empty ({$path})."
                : "Nothing archived at {$path} — already clear.");

            return self::SUCCESS;
        }

        $label = $all ? "the entire archive ({$path})" : "the archived data at {$path}";

        if (! $this->option('force') && ! $this->confirm("This will permanently delete {$label}. Continue?")) {
            $this->warn('Aborted — nothing was deleted.');

            return self::FAILURE;
        }

        $disk->deleteDirectory($path);

        $this->info("Deleted {$label}.");

        return self::SUCCESS;
    }
}
