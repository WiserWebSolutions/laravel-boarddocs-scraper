<?php

namespace BoardDocsScraper\Data;

/**
 * A BoardDocs committee (board, sub-committee, etc.) discovered from a site's
 * public landing page. Mirrors the `Committee` dataclass in the Python exporter.
 */
class CommitteeData
{
    public function __construct(
        public readonly string $committeeId,
        public readonly string $name,
    ) {}

    public function toArray(): array
    {
        return [
            'committee_id' => $this->committeeId,
            'name' => $this->name,
        ];
    }

    /**
     * Rehydrate from the array shape produced by {@see toArray()}. Used to keep
     * cached payloads free of serialized objects (see BoardDocsClient caching).
     *
     * @param  array{committee_id: string, name: string}  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            committeeId: (string) $row['committee_id'],
            name: (string) $row['name'],
        );
    }
}
