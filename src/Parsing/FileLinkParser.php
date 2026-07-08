<?php

namespace BoardDocsScraper\Parsing;

use BoardDocsScraper\Data\AttachmentData;

/**
 * Collects downloadable file links from BoardDocs HTML fragments. Faithful port
 * of parse_public_files_html / parse_file_links_from_html from the Python tool.
 */
class FileLinkParser
{
    /** CSS classes observed on file download anchors. */
    public const FILE_LINK_CLASSES = [
        'public-file',
        'file',
        'administrative-file',
        'executive-file',
        'private-file',
    ];

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
}
