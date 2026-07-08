<?php

namespace BoardDocsScraper\Support;

use BoardDocsScraper\Resources\Committee;
use Illuminate\Support\Collection;

/**
 * The collection returned by Site::committees(). Behaves like a normal
 * Collection of {@see Committee} resources, but adds refresh() to bust the
 * committee cache and re-fetch from the site.
 *
 * @extends Collection<int, Committee>
 */
class CommitteeCollection extends Collection
{
    /** @var (callable(): static)|null */
    protected $refresher = null;

    /**
     * Attach the closure used by refresh() to re-fetch a fresh collection.
     *
     * @param  callable(): static  $refresher
     */
    public function setRefresher(callable $refresher): static
    {
        $this->refresher = $refresher;

        return $this;
    }

    /**
     * Bust the committee cache and return a freshly-fetched collection. Returns
     * the current instance unchanged if no refresher was attached.
     */
    public function refresh(): static
    {
        if ($this->refresher === null) {
            return $this;
        }

        return ($this->refresher)();
    }
}
