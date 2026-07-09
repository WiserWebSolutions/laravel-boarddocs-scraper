<?php

namespace BoardDocsScraper\Tests;

use BoardDocsScraper\BoardDocsServiceProvider;
use BoardDocsScraper\Facades\BoardDocs;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return array_filter([
            BoardDocsServiceProvider::class,
            class_exists(\Laravel\Ai\AiServiceProvider::class) ? \Laravel\Ai\AiServiceProvider::class : null,
        ]);
    }

    protected function getPackageAliases($app): array
    {
        return ['BoardDocs' => BoardDocs::class];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];
        $config->set('boarddocs.site', 'pa/phoe');
        $config->set('boarddocs.http.request_delay', 0);
        $config->set('boarddocs.cache.enabled', true);
        $config->set('boarddocs.output.disk', 'local');
        $config->set('cache.default', 'array');
    }
}
