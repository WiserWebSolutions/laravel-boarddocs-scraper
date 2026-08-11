<?php

namespace BoardDocsScraper\Pdf\Templates;

/**
 * Controls the visual appearance of a generated meeting PDF. Implementations
 * provide two renderings of the same design: a CSS subset for TCPDF's
 * writeHTML() parser, and a full HTML document for headless-Chrome rendering
 * via the browsershot engine.
 */
interface PdfTemplate
{
    /**
     * A <style> block understood by TCPDF's limited writeHTML CSS parser
     * (no flexbox/grid, no custom web fonts — core PDF fonts only).
     */
    public function styleBlock(): string;

    /**
     * A full HTML document (doctype/head/style/body) for headless-Chrome
     * rendering, where the full CSS spec is available.
     */
    public function document(string $body, string $baseUrl): string;
}
