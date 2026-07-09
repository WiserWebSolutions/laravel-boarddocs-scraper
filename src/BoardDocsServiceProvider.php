<?php

namespace BoardDocsScraper;

use BoardDocsScraper\Console\CreateVectorStoreCommand;
use BoardDocsScraper\Console\ScanCommand;
use BoardDocsScraper\Console\SearchCommand;
use Illuminate\Support\ServiceProvider;

class BoardDocsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/boarddocs.php', 'boarddocs');

        // Share a single HTTP client factory so Http::fake() (and any global
        // middleware) applies to the requests this package makes.
        $this->app->singleton(\Illuminate\Http\Client\Factory::class);

        $this->app->singleton('boarddocs', function ($app) {
            return new BoardDocsManager($app, $app['config']['boarddocs']);
        });

        $this->app->alias('boarddocs', BoardDocsManager::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/boarddocs.php' => $this->app->configPath('boarddocs.php'),
            ], 'boarddocs-config');

            $this->commands([
                ScanCommand::class,
                SearchCommand::class,
                CreateVectorStoreCommand::class,
            ]);
        }
    }
}
