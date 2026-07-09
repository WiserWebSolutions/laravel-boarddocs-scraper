<?php

namespace BoardDocsScraper\Data;

/**
 * A downloaded attachment plus the metadata needed for bookmarks and for
 * remapping remote BoardDocs links to in-document anchors. Mirrors the
 * `SavedAttachment` dataclass in the Python exporter.
 *
 * The file is streamed straight to disk on download (see
 * BoardDocsClient::downloadToFile()) rather than held as an in-memory blob,
 * so assembling a meeting with many/large attachments doesn't need to keep
 * every attachment's bytes resident in PHP memory at once.
 */
class SavedAttachment
{
    public function __construct(
        public readonly string $bookmark,
        public readonly string $path,
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
