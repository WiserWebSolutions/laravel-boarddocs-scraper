<?php

namespace BoardDocsScraper\Pdf\Templates;

/**
 * Clean, modern, minimal-color styling: neutral grays for body text, a single
 * muted-blue accent for headings and links, generous line-height/spacing for
 * readability. Ships as the package default (config('boarddocs.pdf.template')).
 */
class DefaultTemplate implements PdfTemplate
{
    /**
     * TCPDF's writeHTML() only understands a small CSS subset (core PDF fonts,
     * no flexbox/grid, unreliable borders on non-table elements), so this stays
     * limited to font/color/spacing declarations that render reliably.
     */
    public function styleBlock(): string
    {
        return <<<'HTML'
        <style>
            body { font-family: helvetica, sans-serif; font-size: 10pt; color: #1f2937; line-height: 1.5; }
            .print-meeting-name { font-family: helvetica, sans-serif; font-size: 16pt; font-weight: bold; text-align: center; color: #111827; }
            .print-meeting-date { font-family: helvetica, sans-serif; font-size: 11pt; text-align: center; color: #6b7280; }
            .category, .wrap-category { font-family: helvetica, sans-serif; font-size: 12pt; font-weight: bold; color: #1e40af; }
            .item { font-family: helvetica, sans-serif; font-size: 10pt; color: #1f2937; }
            a { color: #1d4ed8; }
        </style>
        HTML;
    }

    /**
     * Headless Chrome gets the full CSS spec, so the same palette can be
     * expressed with proper spacing, dividers, and system fonts.
     */
    public function document(string $body, string $baseUrl): string
    {
        $base = htmlspecialchars(rtrim($baseUrl, '/').'/', ENT_QUOTES);

        return <<<HTML
        <!DOCTYPE html>
        <html><head><meta charset="utf-8">
        <base href="{$base}">
        <style>
          :root {
            --text: #1f2937;
            --muted: #6b7280;
            --heading: #111827;
            --accent: #1e40af;
            --link: #1d4ed8;
            --divider: #e5e7eb;
          }
          body {
            font-family: -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            font-size: 11pt;
            line-height: 1.6;
            color: var(--text);
            margin: 32px 40px;
          }
          .print-meeting-name {
            font-size: 18pt;
            font-weight: 600;
            text-align: center;
            color: var(--heading);
            margin-bottom: 4px;
          }
          .print-meeting-date {
            font-size: 11pt;
            text-align: center;
            color: var(--muted);
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--divider);
          }
          .category, .wrap-category {
            font-size: 13pt;
            font-weight: 600;
            color: var(--accent);
            margin-top: 1.4em;
            margin-bottom: 0.5em;
            padding-top: 0.6em;
            border-top: 1px solid var(--divider);
          }
          .item {
            font-size: 11pt;
            color: var(--text);
            margin: 0.5em 0;
          }
          a {
            color: var(--link);
            text-decoration: none;
            word-break: break-word;
          }
          a:hover { text-decoration: underline; }
        </style></head><body>{$body}</body></html>
        HTML;
    }
}
