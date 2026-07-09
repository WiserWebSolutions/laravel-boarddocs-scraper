<?php

namespace BoardDocsScraper\Support;

use BoardDocsScraper\Client\BoardDocsClient;
use BoardDocsScraper\Data\AgendaItemData;
use BoardDocsScraper\Data\AttachmentData;
use BoardDocsScraper\Data\MeetingData;
use BoardDocsScraper\Data\SavedAttachment;
use BoardDocsScraper\Exceptions\BoardDocsException;
use BoardDocsScraper\Parsing\FileLinkParser;

/**
 * Downloads every attachment for a meeting (print-agenda file links, meeting
 * level files, and per-item files) into SavedAttachment records with unique
 * bookmark names. Faithful port of the save_attachment_list flow in
 * export_scope() from the Python exporter.
 */
class AttachmentCollector
{
    public function __construct(protected BoardDocsClient $client)
    {
    }

    /**
     * @param  AgendaItemData[]  $items
     * @return SavedAttachment[]
     */
    public function collect(MeetingData $meeting, string $committeeId, string $printHtml, array $items): array
    {
        $printFiles = FileLinkParser::parse($printHtml);
        $meetingApiFiles = $this->client->fetchItemAttachments($meeting->unique, $committeeId);
        $iconItems = array_values(array_filter($items, fn (AgendaItemData $i) => $i->hasAttachment));

        // One shared temp directory per meeting; every attachment streams
        // straight into it so its bytes never have to live in PHP memory.
        $tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'bdscraper_'.bin2hex(random_bytes(5));
        if (! is_dir($tempDir) && ! mkdir($tempDir, 0777, true) && ! is_dir($tempDir)) {
            throw new BoardDocsException("Could not create temp directory '{$tempDir}' for attachment downloads.");
        }

        $saved = [];
        $usedBookmarks = [];
        $downloaded = [];

        $save = function (array $files, string $itemUnique = '') use (&$saved, &$usedBookmarks, &$downloaded, $tempDir): void {
            foreach ($files as $att) {
                /** @var AttachmentData $att */
                if (isset($downloaded[$att->unique])) {
                    continue;
                }
                $href = Urls::resolveAttachmentUrl($att->href, $this->client->baseUrl());
                $bookmark = $this->uniqueBookmarkName($att->name, $att->unique, $usedBookmarks);
                $path = $tempDir.DIRECTORY_SEPARATOR.$bookmark;

                $size = $this->client->downloadToFile($href, $path);
                if ($size === 0) {
                    @unlink($path);
                    throw new BoardDocsException("Empty download for attachment '{$att->name}' ({$href}).");
                }

                $saved[] = new SavedAttachment(
                    bookmark: $bookmark,
                    path: $path,
                    resolvedUrl: $href,
                    href: $att->href,
                    fileUnique: $att->unique,
                    itemUnique: $itemUnique,
                );
                $downloaded[$att->unique] = true;
            }
        };

        $save($printFiles);
        $save($meetingApiFiles, $meeting->unique);

        foreach ($iconItems as $item) {
            $files = $this->client->fetchItemAttachments($item->unique, $committeeId);
            if (empty($files)) {
                $files = $item->attachments;
            }
            if (empty($files)) {
                continue;
            }
            $save($files, $item->unique);
        }

        return $saved;
    }

    /**
     * @param  array<string, bool>  $used  (by reference)
     */
    protected function uniqueBookmarkName(string $filename, string $fileUnique, array &$used): string
    {
        $base = Urls::sanitizePathComponent($filename) ?: $fileUnique;
        $candidate = $base;
        $suffix = 2;
        while (isset($used[$candidate])) {
            $candidate = $suffix === 2
                ? $base.' ('.substr($fileUnique, 0, 6).')'
                : $base.' ('.$suffix.')';
            $suffix++;
        }
        $used[$candidate] = true;

        return $candidate;
    }
}
