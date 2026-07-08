<?php

namespace BoardDocsScraper\Resources;

use BoardDocsScraper\Client\BoardDocsClient;
use BoardDocsScraper\Data\CommitteeData;
use BoardDocsScraper\Support\CommitteeCollection;

/**
 * Fluent entry point for a single BoardDocs site (e.g. "pa/phoe").
 *
 * Example:
 *   BoardDocs()->site('pa/phoe')->committees()->first()->agenda()->withAttachments()->toPdf();
 */
class Site
{
    public function __construct(
        protected BoardDocsClient $client,
        protected array $config,
        protected string $site,
    ) {
    }

    public function name(): string
    {
        return $this->site;
    }

    public function client(): BoardDocsClient
    {
        return $this->client;
    }

    public function config(): array
    {
        return $this->config;
    }

    /**
     * All committees on the site (cached). Call refresh() on the returned
     * collection to bust the cache and re-fetch.
     *
     * @return CommitteeCollection
     */
    public function committees(): CommitteeCollection
    {
        return $this->buildCommittees(false);
    }

    /**
     * Build the committee collection, optionally bypassing (and refreshing) the
     * cache. The returned collection carries a refresher so callers can do
     * committees()->refresh() to force a re-fetch.
     */
    protected function buildCommittees(bool $refresh): CommitteeCollection
    {
        $committees = CommitteeCollection::make($this->client->discoverCommittees(null, $refresh))
            ->map(fn (CommitteeData $data) => new Committee(
                $this, $this->client, $this->config, $data->committeeId, $data->name
            ))
            ->values();

        return $committees->setRefresher(fn () => $this->buildCommittees(true));
    }

    /**
     * Find a committee by its BoardDocs id, or null.
     */
    public function committee(string $committeeId): ?Committee
    {
        return $this->committees()->first(
            fn (Committee $c) => strcasecmp($c->committeeId, $committeeId) === 0
        );
    }

    /**
     * Find a committee by (case-insensitive, partial) name, or null.
     */
    public function committeeNamed(string $name): ?Committee
    {
        $needle = strtolower($name);

        return $this->committees()->first(
            fn (Committee $c) => str_contains(strtolower($c->name), $needle)
        );
    }
}
