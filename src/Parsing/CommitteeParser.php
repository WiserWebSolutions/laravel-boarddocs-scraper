<?php

namespace BoardDocsScraper\Parsing;

use BoardDocsScraper\Data\CommitteeData;

/**
 * Extracts committees from a BoardDocs public landing page. Faithful port of
 * BoardDocsClient.discover_committees from the Python tool.
 */
class CommitteeParser
{
    /**
     * @return CommitteeData[]
     */
    public static function parse(string $html): array
    {
        $committees = [];
        $seen = [];

        $re = '/committee-trigger[^>]*committeeid="([^"]+)"[^>]*aria-label="([^"]+)"/i';
        if (preg_match_all($re, $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $cid = $m[1];
                $name = html_entity_decode(trim($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (! isset($seen[$cid])) {
                    $seen[$cid] = true;
                    $committees[] = new CommitteeData($cid, $name);
                }
            }
        }

        if (! empty($committees)) {
            return $committees;
        }

        // Fallback: committee ids embedded without labels.
        if (preg_match_all('/committeeid="([A-Z0-9]{10,15})"/i', $html, $matches)) {
            $ids = array_unique($matches[1]);
            sort($ids);
            foreach ($ids as $cid) {
                if (! isset($seen[$cid])) {
                    $seen[$cid] = true;
                    $committees[] = new CommitteeData($cid, $cid);
                }
            }
        }

        return $committees;
    }
}
