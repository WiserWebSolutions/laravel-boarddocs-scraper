<?php

namespace BoardDocsScraper\Resources;

use BoardDocsScraper\Client\BoardDocsClient;
use Illuminate\Support\Collection;

/**
 * A committee on a BoardDocs site. Exposes its meetings and a convenience
 * accessor for the latest meeting's agenda.
 */
class Committee
{
    public function __construct(
        protected Site $site,
        protected BoardDocsClient $client,
        protected array $config,
        public readonly string $committeeId,
        public readonly string $name,
    ) {
    }

    public function site(): Site
    {
        return $this->site;
    }

    /**
     * All meetings for this committee (newest first, cached).
     *
     * @return Collection<int, Meeting>
     */
    public function meetings(): Collection
    {
        return collect($this->client->listMeetings($this->committeeId))
            ->map(fn ($data) => new Meeting($this, $this->client, $this->config, $data))
            ->values();
    }

    /**
     * Find a meeting by its BoardDocs unique id.
     */
    public function meeting(string $unique): ?Meeting
    {
        return $this->meetings()->first(fn (Meeting $m) => $m->unique() === $unique);
    }

    /**
     * The most recent meeting, or null.
     */
    public function latest(): ?Meeting
    {
        return $this->meetings()->first();
    }

    /**
     * Convenience: the latest meeting's agenda.
     */
    public function agenda(): ?Agenda
    {
        return $this->latest()?->agenda();
    }

    public function toArray(): array
    {
        return [
            'committee_id' => $this->committeeId,
            'name' => $this->name,
        ];
    }
}
