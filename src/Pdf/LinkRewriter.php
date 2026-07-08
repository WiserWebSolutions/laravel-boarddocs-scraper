<?php

namespace BoardDocsScraper\Pdf;

use BoardDocsScraper\Data\SavedAttachment;
use BoardDocsScraper\Support\Urls;
use DOMDocument;

/**
 * Rewrites remote BoardDocs links in the agenda HTML into internal PDF page
 * links that jump to the merged attachment pages.
 *
 * This is the PHP counterpart to the Python remap. Pure PHP cannot rewrite the
 * link annotations of an imported PDF after the fact, so instead we resolve each
 * BoardDocs link to the (already computed) destination page and emit it as a
 * TCPDF numeric internal link ("#<page>"), which writeHTML turns into a GoTo.
 */
class LinkRewriter
{
    /**
     * Rewrite anchors whose target attachment has a known destination page.
     *
     * @param  SavedAttachment[]  $saved
     * @param  array<int, int>  $pageForIndex  saved-index => 1-based PDF page
     * @return array{0:string,1:array<int,bool>}  [rewritten fragment, referenced indexes]
     */
    public static function rewrite(string $htmlFragment, array $saved, string $baseUrl, string $site, array $pageForIndex): array
    {
        if (empty($saved) || empty($pageForIndex)) {
            return [$htmlFragment, []];
        }

        // Map BoardDocs document ids (file "unique" and item "unique") to the
        // index of the saved attachment they resolve to.
        $indexByDocId = [];
        foreach ($saved as $i => $att) {
            $indexByDocId[strtolower($att->fileUnique)] = $i;
            if ($att->itemUnique !== '') {
                $indexByDocId[strtolower($att->itemUnique)] = $i;
            }
        }

        $doc = new DOMDocument;
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="UTF-8"?><div id="__bd_wrap__">'.$htmlFragment.'</div>',
            LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $referenced = [];

        foreach (iterator_to_array($doc->getElementsByTagName('a')) as $a) {
            $href = trim($a->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#') || stripos($href, 'mailto:') === 0) {
                continue;
            }

            $norm = Urls::normalizeLinkUri($href, $baseUrl);
            if (! Urls::isBoardDocsUrl($href, $site) && ! Urls::isBoardDocsUrl($norm, $site)) {
                continue;
            }

            $docId = Urls::extractDocumentId($norm);
            if ($docId === null) {
                continue;
            }

            $idx = $indexByDocId[strtolower($docId)] ?? null;
            if ($idx === null || ! isset($pageForIndex[$idx])) {
                continue;
            }

            $a->setAttribute('href', '#'.$pageForIndex[$idx]);
            $a->setAttribute('title', 'Attachment in this PDF: '.$saved[$idx]->bookmark);
            $referenced[$idx] = true;
        }

        $wrapper = $doc->getElementById('__bd_wrap__');
        $inner = '';
        if ($wrapper) {
            foreach ($wrapper->childNodes as $child) {
                $inner .= $doc->saveHTML($child);
            }
        }

        return [$inner, $referenced];
    }
}
