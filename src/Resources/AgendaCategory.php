<?php

namespace BoardDocsScraper\Resources;

use BoardDocsScraper\Data\AgendaItemData;
use Illuminate\Support\Collection;

/**
 * An ordered section of an agenda (e.g. "A. OPENING OF MEETING"). Groups the
 * agenda items that fall under it, each carrying its subject and content.
 */
class AgendaCategory
{
    /**
     * @param  Collection<int, AgendaItemData>  $items
     */
    public function __construct(
        public readonly string $order,
        public readonly string $name,
        protected Collection $items,
    ) {
    }

    public function order(): string
    {
        return $this->order;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * The agenda items in this category (subject + content).
     *
     * @return Collection<int, AgendaItemData>
     */
    public function items(): Collection
    {
        return $this->items;
    }

    /**
     * @return array{order: string, name: string, items: array<int, array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'order' => $this->order,
            'name' => $this->name,
            'items' => $this->items->map(fn (AgendaItemData $item) => $item->toArray())->all(),
        ];
    }
}
