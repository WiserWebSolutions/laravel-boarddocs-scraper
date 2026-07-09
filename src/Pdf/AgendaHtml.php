<?php

namespace BoardDocsScraper\Pdf;

/**
 * Normalizes BoardDocs print-agenda HTML for PDF rendering. Ports the cleanup in
 * prepare_agenda_html() from the Python exporter (strip script/style, wrap).
 */
class AgendaHtml
{
    /**
     * Strip scripts/styles and return the inner <body> markup as a fragment.
     */
    public static function clean(string $rawHtml): string
    {
        $html = preg_replace('/<(script|style|noscript)\b[^>]*>[\s\S]*?<\/\1>/i', '', $rawHtml);
        $html = self::stripUnparsableBorderNone($html);

        if (preg_match('/<body\b[^>]*>([\s\S]*?)<\/body>/i', $html, $m)) {
            return $m[1];
        }

        return $html;
    }

    /**
     * TCPDF's getCSSBorderStyle() misreads the 2-token "<width> none" border
     * shorthand (e.g. "border: 0px none") as [style, color] instead of
     * [width, style], then tries to resolve "none" as a color and hits a
     * case-mismatched array lookup in TCPDF_COLORS::getSpotColor(), which
     * throws "Undefined array key \"None\"" and aborts the whole render.
     * A "none" border already paints nothing, so the declaration is safe to
     * drop outright rather than trip that bug.
     */
    protected static function stripUnparsableBorderNone(string $html): string
    {
        return preg_replace_callback('/style\s*=\s*"([^"]*)"/i', function (array $m) {
            $style = preg_replace(
                '/border(-top|-right|-bottom|-left)?\s*:\s*[\d.]+(px|pt|em|rem|%)?\s+none\s*;?\s*/i',
                '',
                $m[1]
            );

            return 'style="'.$style.'"';
        }, $html);
    }

    /**
     * A minimal CSS block understood by TCPDF's writeHTML.
     */
    public static function styleBlock(): string
    {
        return <<<'HTML'
        <style>
            body { font-family: helvetica, sans-serif; font-size: 10pt; }
            .print-meeting-date, .print-meeting-name { font-weight: bold; text-align: center; }
            .category, .wrap-category { font-weight: bold; }
            .item { }
            a { color: #0645ad; }
        </style>
        HTML;
    }

    /**
     * A fragment suitable for TCPDF::writeHTML (style block + cleaned body).
     */
    public static function fragment(string $rawHtml): string
    {
        return self::styleBlock()."\n".self::clean($rawHtml);
    }

    /**
     * A full HTML document suitable for headless-Chrome rendering.
     */
    public static function document(string $rawHtml, string $baseUrl): string
    {
        $body = self::clean($rawHtml);
        $base = htmlspecialchars(rtrim($baseUrl, '/').'/', ENT_QUOTES);

        return <<<HTML
        <!DOCTYPE html>
        <html><head><meta charset="utf-8">
        <base href="{$base}">
        <style>
          body { font-family: Arial, Helvetica, sans-serif; font-size: 11pt; margin: 24px; }
          .print-meeting-date, .print-meeting-name { font-weight: bold; text-align: center; }
          .category, .wrap-category { font-weight: bold; margin-top: 1em; }
          .item { margin: 0.4em 0; }
          a { color: #0645ad; word-break: break-word; }
        </style></head><body>{$body}</body></html>
        HTML;
    }
}
