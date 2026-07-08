<?php

namespace BoardDocsScraper\Pdf;

/**
 * The output of a PdfEngine: the PDF bytes plus the structural metadata needed
 * to build a search-index entry without re-parsing the PDF.
 */
class RenderedPdf
{
    /**
     * @param  array<int, array{title:string, page:int}>  $toc  bookmarks; first is "Agenda", rest are attachments
     */
    public function __construct(
        public readonly string $bytes,
        public readonly int $pageCount,
        public readonly array $toc,
    ) {
    }

    /**
     * Attachment bookmarks only (everything after the leading "Agenda" entry),
     * matching the index.jsonl "attachments" shape from the original project.
     *
     * @return array<int, array{title:string, page:int}>
     */
    public function attachmentToc(): array
    {
        return array_values(array_slice($this->toc, 1));
    }
}
