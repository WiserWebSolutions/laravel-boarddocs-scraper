<?php

namespace BoardDocsScraper\Parsing;

use BoardDocsScraper\Data\AttachmentData;

/**
 * Collects downloadable file links from BoardDocs HTML fragments. Faithful port
 * of parse_public_files_html / parse_file_links_from_html from the Python tool.
 */
class FileLinkParser
{
    /**
     * CSS classes observed on *public* file download anchors. This package
     * is public-data-only (see README), and administrative/executive/private
     * file anchors are intentionally excluded: BoardDocs renders those dead
     * anchors even on the public print agenda, but never actually serves the
     * underlying file to the public — downloading one 404s every time.
     */
    public const FILE_LINK_CLASSES = [
        'public-file',
        'file',
    ];

    /** Class-name substrings marking a non-public file link to skip. */
    protected const NON_PUBLIC_MARKERS = ['admin', 'executive', 'private'];

    /**
     * @return AttachmentData[]
     */
    public static function parsePublicFiles(string $filesHtml, string $className = 'public-file'): array
    {
        $attachments = [];
        $pattern = '/class="'.preg_quote($className, '/').'"[^>]*unique="([^"]+)"[^>]*href="([^"]+)"[^>]*>([^<]*)<\/a>/i';

        if (preg_match_all($pattern, $filesHtml, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $rawName = self::decode(trim($m[3]));
                [$name, $size] = self::splitSize($rawName);
                $attachments[] = new AttachmentData(
                    unique: $m[1],
                    href: $m[2],
                    name: $name,
                    size: $size,
                );
            }
        }

        return $attachments;
    }

    /**
     * @return AttachmentData[]
     */
    public static function parse(string $fragment): array
    {
        $attachments = [];
        $seen = [];

        foreach (self::FILE_LINK_CLASSES as $className) {
            foreach (self::parsePublicFiles($fragment, $className) as $att) {
                if (! isset($seen[$att->unique])) {
                    $seen[$att->unique] = true;
                    $attachments[] = $att;
                }
            }
        }

        // Anchors carrying a "file" class in either attribute order.
        $anchorRe = '/<a[^>]*class="([^"]*file[^"]*)"[^>]*(?:unique="([^"]+)"[^>]*href="([^"]+)"'
            .'|href="([^"]+)"[^>]*unique="([^"]+)")[^>]*>([^<]*)<\/a>/i';

        if (preg_match_all($anchorRe, $fragment, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                if (($m[2] ?? '') !== '') {
                    $unique = $m[2];
                    $href = $m[3];
                    $rawName = $m[6] ?? '';
                } else {
                    $href = $m[4] ?? '';
                    $unique = $m[5] ?? '';
                    $rawName = $m[6] ?? '';
                }
                $unique = strtoupper($unique);
                if ($unique === '' || isset($seen[$unique])) {
                    continue;
                }
                if (self::isNonPublicClass($m[1])) {
                    // Mark as seen so the class-blind bare /files/ID/
                    // fallback below doesn't re-add this non-public anchor.
                    $seen[$unique] = true;

                    continue;
                }
                $seen[$unique] = true;
                [$name, $size] = self::splitSize(self::decode(trim($rawName)));
                $attachments[] = new AttachmentData($unique, $href, $name, $size);
            }
        }

        // Bare /files/ID/ links.
        if (preg_match_all('#<a[^>]*href="([^"]*/files/([A-Z0-9]{10,15})[^"]*)"[^>]*>([^<]*)</a>#i', $fragment, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $unique = strtoupper($m[2]);
                if (isset($seen[$unique])) {
                    continue;
                }
                $seen[$unique] = true;
                [$name, $size] = self::splitSize(self::decode(trim($m[3])));
                $attachments[] = new AttachmentData($unique, $m[1], $name, $size);
            }
        }

        return $attachments;
    }

    /**
     * Split a "Report Name (1.2 MB)" label into [name, size].
     *
     * @return array{0:string,1:string}
     */
    private static function splitSize(string $rawName): array
    {
        if (preg_match('/^(.*?)\s*\(([^)]+)\)\s*$/', $rawName, $m)) {
            return [trim($m[1]), trim($m[2])];
        }

        return [$rawName, ''];
    }

    private static function decode(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function isNonPublicClass(string $classAttr): bool
    {
        $lower = strtolower($classAttr);

        foreach (self::NON_PUBLIC_MARKERS as $marker) {
            if (str_contains($lower, $marker)) {
                return true;
            }
        }

        return false;
    }
}
