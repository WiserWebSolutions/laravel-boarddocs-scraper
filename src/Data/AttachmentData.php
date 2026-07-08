<?php

namespace BoardDocsScraper\Data;

/**
 * A downloadable file link parsed from a BoardDocs agenda / file endpoint.
 * Mirrors the `Attachment` dataclass in the Python exporter.
 */
class AttachmentData
{
    public function __construct(
        public readonly string $unique,
        public readonly string $href,
        public readonly string $name,
        public readonly string $size = '',
    ) {
    }

    public function isPdf(): bool
    {
        return str_ends_with(strtolower($this->name), '.pdf')
            || str_contains(strtolower($this->href), '.pdf');
    }

    public function toArray(): array
    {
        return [
            'unique' => $this->unique,
            'href' => $this->href,
            'name' => $this->name,
            'size' => $this->size,
        ];
    }
}
