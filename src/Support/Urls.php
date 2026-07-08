<?php

namespace BoardDocsScraper\Support;

/**
 * URL / path helpers ported from the Python exporter. These are pure functions
 * responsible for resolving attachment URLs, normalizing link URIs, and
 * building the lookup keys used to remap BoardDocs links to PDF pages.
 */
class Urls
{
    /**
     * BoardDocs returns attachment paths like /pa/phoe/Board.nsf/files/ID/$file/name.pdf.
     * Do not naively join with the base URL — that duplicates the path.
     */
    public static function resolveAttachmentUrl(string $href, string $baseUrl): string
    {
        $href = trim($href);

        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }

        if (str_starts_with($href, '/')) {
            return 'https://go.boarddocs.com'.$href;
        }

        return rtrim($baseUrl, '/').'/'.ltrim($href, '/');
    }

    public static function sanitizePathComponent(string $value): string
    {
        $cleaned = preg_replace('/[<>:"\/\\\\|?*]/', '-', $value);
        $cleaned = preg_replace('/\s+/', ' ', $cleaned);
        $cleaned = rtrim(trim($cleaned), '.');

        return $cleaned !== '' ? $cleaned : 'unknown';
    }

    public static function districtIdFromSite(string $site): string
    {
        return str_replace('/', '-', trim($site, '/'));
    }

    public static function isBoardDocsUrl(string $url, string $site): bool
    {
        $lower = strtolower($url);

        if (str_contains($lower, 'boarddocs.com')) {
            return true;
        }

        if (str_contains($lower, 'board.nsf')) {
            return true;
        }

        return str_contains($lower, '/'.trim($site, '/').'/');
    }

    public static function normalizeLinkUri(string $uri, string $baseUrl): string
    {
        $uri = urldecode(trim($uri));

        if (str_starts_with($uri, '//')) {
            $uri = 'https:'.$uri;
        } elseif (str_starts_with($uri, '/')) {
            $uri = 'https://go.boarddocs.com'.$uri;
        } elseif (! str_starts_with($uri, 'http')) {
            $uri = self::resolveAttachmentUrl($uri, $baseUrl);
        }

        $uri = explode('#', $uri)[0];

        return strtolower(rtrim($uri, '/'));
    }

    /**
     * The file "unique" from /files/ID/ or the agenda/item id from ?id=.
     */
    public static function extractDocumentId(string $url): ?string
    {
        if (preg_match('/[?&]id=([^&#]+)/i', $url, $m)) {
            return $m[1];
        }

        if (preg_match('#/files/([^/]+)/#i', $url, $m)) {
            return $m[1];
        }

        return null;
    }

    public static function pathOf(string $normalizedUri): string
    {
        return strtolower((string) parse_url($normalizedUri, PHP_URL_PATH));
    }

    /**
     * Build a set of lookup keys for one attachment so that any of the URL forms
     * BoardDocs might emit in the agenda HTML can be resolved to the attachment.
     *
     * @return string[]
     */
    public static function lookupKeys(
        string $resolvedUrl,
        string $href,
        string $fileUnique,
        string $baseUrl,
        string $site,
    ): array {
        $keys = [];

        foreach ([$resolvedUrl, $href] as $raw) {
            if ($raw === '') {
                continue;
            }
            $norm = self::normalizeLinkUri($raw, $baseUrl);
            $keys[] = $norm;
            $path = self::pathOf($norm);
            $keys[] = $path;
            if (str_contains($path, '/board.nsf')) {
                $keys[] = substr($path, strpos($path, '/board.nsf'));
            }
        }

        if ($fileUnique !== '') {
            $keys[] = strtolower($fileUnique);
            $keys[] = strtoupper($fileUnique);
        }

        $siteSlug = strtolower(trim($site, '/'));
        if ($siteSlug !== '' && $fileUnique !== '') {
            $keys[] = '/'.$siteSlug.'/board.nsf/files/'.strtolower($fileUnique);
        }

        return array_values(array_unique(array_filter($keys, fn ($k) => $k !== '')));
    }
}
