<?php

namespace BoardDocsScraper;

use BoardDocsScraper\Client\BoardDocsClient;
use BoardDocsScraper\Index\IndexBuilder;
use BoardDocsScraper\Index\IndexSearcher;
use BoardDocsScraper\Resources\Site;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * The package entry point (bound as "boarddocs"). Produces fluent Site objects
 * and the shared index services.
 *
 * @example BoardDocs()->site('pa/phoe')->committees()->first()->agenda()->withAttachments()->toPdf();
 */
class BoardDocsManager
{
    public function __construct(
        protected Container $app,
        protected array $config,
    ) {
    }

    /**
     * Begin a fluent chain for a BoardDocs site (defaults to config('boarddocs.site')).
     *
     * @param  array  $overrides  config overrides merged over config('boarddocs')
     */
    public function site(?string $site = null, array $overrides = []): Site
    {
        $site ??= $this->config['site'];
        $config = $this->mergedConfig($overrides);

        return new Site($this->client($site, $overrides), $config, $site);
    }

    /**
     * The low-level HTTP client for a site (the "unofficial API").
     *
     * @param  array  $overrides  config overrides merged over config('boarddocs')
     */
    public function client(?string $site = null, array $overrides = []): BoardDocsClient
    {
        $site ??= $this->config['site'];
        $config = $this->mergedConfig($overrides);

        return new BoardDocsClient(
            $site,
            $config,
            $this->app->make(HttpFactory::class),
            $this->cache($config),
        );
    }

    public function searcher(): IndexSearcher
    {
        return new IndexSearcher($this->config);
    }

    public function indexBuilder(): IndexBuilder
    {
        return new IndexBuilder($this->config);
    }

    public function config(): array
    {
        return $this->config;
    }

    protected function mergedConfig(array $overrides): array
    {
        return empty($overrides)
            ? $this->config
            : array_replace_recursive($this->config, $overrides);
    }

    protected function cache(array $config): CacheRepository
    {
        $store = $config['cache']['store'] ?? null;

        return $this->app->make(CacheFactory::class)->store($store);
    }
}
