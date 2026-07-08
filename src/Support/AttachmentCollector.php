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

        $saved = [];
        $usedBookmarks = [];
        $downloaded = [];

        $save = function (array $files, string $itemUnique = '') use (&$saved, &$usedBookmarks, &$downloaded): void {
            foreach ($files as $att) {
                /** @var AttachmentData $att */
                if (isset($downloaded[$att->unique])) {
                    continue;
                }
                $href = Urls::resolveAttachmentUrl($att->href, $this->client->baseUrl());
                $blob = $this->client->getBytes($href);
                if ($blob === '') {
                    throw new BoardDocsException("Empty download for attachment '{$att->name}' ({$href}).");
                }
                $bookmark = $this->uniqueBookmarkName($att->name, $att->unique, $usedBookmarks);
                $saved[] = new SavedAttachment(
                    bookmark: $bookmark,
                    blob: $blob,
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
