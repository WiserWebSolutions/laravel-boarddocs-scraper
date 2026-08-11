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
        $html = self::stripFontFormatting($html);

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
     * BoardDocs source HTML carries per-element inline font/position styling
     * (font-family, font-size, line-height, color, absolute positioning, ...)
     * copied from whatever word processor authored the agenda. TCPDF's HTML
     * renderer applies those literally, and mismatched line-heights/positions
     * between adjacent elements is what produces the overlapping text seen in
     * rendered PDFs. Strip that formatting outright and let the default body
     * font and the heading classes in the active PdfTemplate control
     * appearance uniformly, rather than trying to preserve source styling.
     */
    protected static function stripFontFormatting(string $html): string
    {
        $html = preg_replace('/<\/?font\b[^>]*>/i', '', $html);

        $disallowed = '/^(font(-.*)?|line-height|letter-spacing|color|text-decoration|position|top|left|right|bottom|vertical-align|text-indent|white-space)$/i';

        $html = preg_replace_callback('/style\s*=\s*"([^"]*)"/i', function (array $m) use ($disallowed) {
            $kept = [];
            foreach (array_filter(array_map('trim', explode(';', $m[1]))) as $prop) {
                $name = trim(explode(':', $prop, 2)[0]);
                if (! preg_match($disallowed, $name)) {
                    $kept[] = $prop;
                }
            }

            return $kept ? 'style="'.implode('; ', $kept).';"' : '';
        }, $html);

        return preg_replace('/\s+(size|face)\s*=\s*"[^"]*"/i', '', $html);
    }
}
