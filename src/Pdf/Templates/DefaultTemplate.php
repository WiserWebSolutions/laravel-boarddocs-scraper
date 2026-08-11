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
            .print-meeting-name { font-family: helvetica, sans-serif; font-size: 17pt; font-weight: bold; text-align: center; color: #111827; }
            .print-meeting-date { font-family: helvetica, sans-serif; font-size: 11pt; text-align: center; color: #6b7280; padding-bottom: 8px; border-bottom: 1px solid #cbd5e1; }
            .category, .wrap-category { font-family: helvetica, sans-serif; font-size: 12pt; font-weight: bold; color: #ffffff; background-color: #1e40af; padding: 6px 10px; }
            .item { font-family: helvetica, sans-serif; font-size: 10pt; color: #1f2937; }
            .leftcol { font-family: helvetica, sans-serif; font-size: 9pt; font-weight: bold; color: #6b7280; }
            .rightcol { font-family: helvetica, sans-serif; font-size: 10pt; color: #1f2937; }
            .itembody { font-family: helvetica, sans-serif; font-size: 10pt; color: #1f2937; }
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
            --accent-soft: #eff6ff;
            --accent-soft-border: #bfdbfe;
            --link: #1d4ed8;
            --divider: #e5e7eb;
          }
          body {
            font-family: -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            font-size: 11pt;
            line-height: 1.6;
            color: var(--text);
            margin: 0;
            padding: 40px 44px;
          }
          .print-meeting-name {
            font-size: 20pt;
            font-weight: 700;
            text-align: center;
            color: var(--heading);
            letter-spacing: -0.01em;
            margin-bottom: 6px;
          }
          .print-meeting-date {
            font-size: 11pt;
            text-align: center;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin-bottom: 28px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--accent);
          }
          .category, .wrap-category {
            font-size: 12pt;
            font-weight: 700;
            color: var(--accent);
            text-transform: uppercase;
            letter-spacing: 0.04em;
            background: var(--accent-soft);
            border-left: 4px solid var(--accent);
            border-radius: 0 4px 4px 0;
            padding: 8px 14px;
            margin-top: 1.8em;
            margin-bottom: 0.8em;
          }
          .item {
            font-size: 11pt;
            color: var(--text);
            margin: 0.6em 0 1.2em;
          }
          dl.row {
            display: flex;
            gap: 8px;
            margin: 2px 0;
          }
          dt {
            font-weight: 600;
            font-size: 9pt;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.03em;
            min-width: 70px;
          }
          dd { margin: 0; color: var(--text); }
          .itembody { margin-top: 6px; }
          .itembody p { margin: 0.4em 0; }
          a {
            color: var(--link);
            text-decoration: none;
            word-break: break-word;
          }
          a:hover { text-decoration: underline; }
          a.public-file, a.print-file {
            display: inline-block;
            color: var(--accent);
            background: var(--accent-soft);
            border: 1px solid var(--accent-soft-border);
            border-radius: 4px;
            padding: 2px 8px;
            font-size: 10pt;
          }
          a.public-file::before, a.print-file::before { content: "📎 "; }
        </style></head><body>{$body}</body></html>
        HTML;
    }
}
