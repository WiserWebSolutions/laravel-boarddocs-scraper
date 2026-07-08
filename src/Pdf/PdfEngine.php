<?php

namespace BoardDocsScraper\Pdf;

interface PdfEngine
{
    /**
     * Render a meeting document to a PDF (bytes + structural metadata).
     */
    public function render(MeetingDocument $document): RenderedPdf;
}
