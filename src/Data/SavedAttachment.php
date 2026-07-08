<?php

namespace BoardDocsScraper\Data;

/**
 * A downloaded attachment plus the metadata needed for bookmarks and for
 * remapping remote BoardDocs links to in-document anchors. Mirrors the
 * `SavedAttachment` dataclass in the Python exporter.
 */
class SavedAttachment
{
    public function __construct(
        public readonly string $bookmark,
        public readonly string $blob,
        public readonly string $resolvedUrl,
        public readonly string $href,
        public readonly string $fileUnique,
        public readonly string $itemUnique = '',
    ) {
    }

    public function isPdf(): bool
    {
        return str_ends_with(strtolower($this->bookmark), '.pdf');
    }
}
