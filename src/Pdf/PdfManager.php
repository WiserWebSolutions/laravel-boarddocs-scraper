<?php

namespace BoardDocsScraper\Pdf;

use BoardDocsScraper\Exceptions\BoardDocsException;
use BoardDocsScraper\Pdf\Engines\BrowsershotEngine;
use BoardDocsScraper\Pdf\Engines\TcpdfEngine;

/**
 * Resolves the configured PDF engine and renders a MeetingDocument.
 */
class PdfManager
{
    public function __construct(protected array $config)
    {
    }

    public function render(MeetingDocument $document): RenderedPdf
    {
        return $this->engine()->render($document);
    }

    public function engine(?string $name = null): PdfEngine
    {
        $name ??= $this->config['pdf']['engine'] ?? 'tcpdf';

        return match ($name) {
            'tcpdf' => new TcpdfEngine,
            'browsershot' => new BrowsershotEngine($this->config['pdf']['browsershot'] ?? []),
            default => throw new BoardDocsException("Unknown PDF engine [{$name}]. Use 'tcpdf' or 'browsershot'."),
        };
    }
}
