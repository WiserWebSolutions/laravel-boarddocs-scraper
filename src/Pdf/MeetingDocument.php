<?php

namespace BoardDocsScraper\Pdf;

use BoardDocsScraper\Data\SavedAttachment;

/**
 * An immutable description of the PDF to build for one meeting: the agenda HTML,
 * the downloaded attachments, and the rendering options. Passed to a PdfEngine.
 */
class MeetingDocument
{
    /**
     * @param  SavedAttachment[]  $savedAttachments
     * @param  array<string, mixed>  $options  the config('boarddocs.pdf') array
     */
    public function __construct(
        public readonly string $agendaHtml,
        public readonly string $baseUrl,
        public readonly string $site,
        public readonly array $savedAttachments,
        public readonly array $options,
        public readonly string $title,
        public readonly string $date,
        public readonly string $committee,
        public readonly string $filename,
    ) {
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    public function selfContained(): bool
    {
        return (bool) $this->option('self_contained', true);
    }

    public function remapLinks(): bool
    {
        return (bool) $this->option('remap_links', true);
    }

    public function embedNonPdf(): bool
    {
        return (bool) $this->option('embed_non_pdf', true);
    }
}
