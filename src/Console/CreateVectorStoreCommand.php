<?php

namespace BoardDocsScraper\Console;

use Illuminate\Console\Command;
use Laravel\Ai\Stores;

/**
 * Creates a Laravel AI SDK vector store for BoardDocs agenda search (see the
 * "Vector store search" section of the README) and prints/writes the config
 * needed to point BoardDocsAgent at it.
 */
class CreateVectorStoreCommand extends Command
{
    protected $signature = 'boarddocs:vector-store:create
        {name=BoardDocs Agendas : Name for the new vector store}
        {--provider= : AI provider to create the store with (defaults to the app config)}
        {--write-env : Write BOARDDOCS_AI_SEARCH_DRIVER and BOARDDOCS_VECTOR_STORE_ID(_PROVIDER) into .env}';

    protected $description = 'Create a vector store (Laravel AI SDK) for BoardDocs agenda search.';

    public function handle(): int
    {
        if (! class_exists(Stores::class)) {
            $this->error('laravel/ai is not installed. Run: composer require laravel/ai');

            return self::FAILURE;
        }

        $name = (string) $this->argument('name');
        $provider = $this->option('provider');

        $store = Stores::create($name, provider: $provider);

        $this->info("Created vector store \"{$store->name}\": {$store->id}");

        if ($this->option('write-env')) {
            $this->writeEnv($store->id, $provider);
        } else {
            $this->newLine();
            $this->line('Add these to your .env (or pass --write-env to do it automatically):');
            $this->line('  BOARDDOCS_AI_SEARCH_DRIVER=vector');
            $this->line("  BOARDDOCS_VECTOR_STORE_ID={$store->id}");
            if ($provider) {
                $this->line("  BOARDDOCS_VECTOR_STORE_PROVIDER={$provider}");
            }
        }

        $this->newLine();
        $this->line('Run `php artisan boarddocs:scan` to populate the store with exported meeting PDFs.');

        return self::SUCCESS;
    }

    protected function writeEnv(string $storeId, ?string $provider): void
    {
        $path = base_path('.env');

        if (! is_file($path)) {
            $this->warn('.env not found; skipping --write-env. Add the values above manually.');

            return;
        }

        $contents = (string) file_get_contents($path);
        $contents = $this->setEnvValue($contents, 'BOARDDOCS_AI_SEARCH_DRIVER', 'vector');
        $contents = $this->setEnvValue($contents, 'BOARDDOCS_VECTOR_STORE_ID', $storeId);

        if ($provider) {
            $contents = $this->setEnvValue($contents, 'BOARDDOCS_VECTOR_STORE_PROVIDER', $provider);
        }

        file_put_contents($path, $contents);

        $this->info('.env updated.');
    }

    protected function setEnvValue(string $contents, string $key, string $value): string
    {
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

        if (preg_match($pattern, $contents) === 1) {
            return (string) preg_replace($pattern, "{$key}={$value}", $contents);
        }

        return rtrim($contents, "\n")."\n{$key}={$value}\n";
    }
}
