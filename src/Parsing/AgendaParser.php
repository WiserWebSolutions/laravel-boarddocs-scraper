<?php

namespace BoardDocsScraper\Parsing;

use BoardDocsScraper\Data\AgendaItemData;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Parses the BD-GetAgenda HTML into structured agenda items. Faithful port of
 * parse_agenda_items / parse_agenda_categories / classify_content_section.
 */
class AgendaParser
{
    /** @var array<array{0:string,1:string}> marker => section */
    public const CONTENT_SECTION_MARKERS = [
        ['public content', 'public'],
        ['administrative content', 'administrative'],
        ['executive content', 'executive'],
    ];

    /**
     * @return AgendaItemData[]
     */
    public static function parseItems(string $agendaHtml): array
    {
        $items = [];
        $categories = self::parseCategories($agendaHtml);

        $itemRe = '/class="[^"]*item[^"]*"[^>]*id="([^"]+)"[^>]*unique="([^"]+)"'
            .'[^>]*Xtitle="([^"]*)"[^>]*>([\s\S]*?)<\/li>/i';

        if (preg_match_all($itemRe, $agendaHtml, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $m) {
                $start = $m[0][1];
                $body = $m[4][0];

                $order = '';
                if (preg_match('/<span[^>]*>([^<]*)<\/span>/', $body, $om)) {
                    $order = trim($om[1]);
                }

                $inlineAttachments = FileLinkParser::parse($body);
                $hasFileIcon = str_contains($body, 'fa-file-text-o');

                $categoryName = '';
                $contentSection = 'other';
                foreach ($categories as $cat) {
                    if ($cat['pos'] < $start) {
                        $categoryName = $cat['name'];
                        $contentSection = self::classifyContentSection($cat['name']);
                    } else {
                        break;
                    }
                }

                $items[] = new AgendaItemData(
                    unique: $m[2][0],
                    order: $order,
                    title: self::decode($m[3][0]),
                    hasAttachment: ! empty($inlineAttachments) || $hasFileIcon,
                    attachments: $inlineAttachments,
                    contentSection: $contentSection,
                    categoryName: $categoryName,
                );
            }
        }

        return $items;
    }

    /**
     * @return array<array{pos:int,unique:string,name:string}>
     */
    public static function parseCategories(string $agendaHtml): array
    {
        $categories = [];
        $catRe = '/class="category[^"]*"[^>]*unique="([^"]+)"[^>]*>([\s\S]*?)<\/li>/i';

        if (preg_match_all($catRe, $agendaHtml, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $m) {
                $name = '';
                if (preg_match_all('/<span[^>]*>([^<]*)<\/span>/', $m[2][0], $spans)) {
                    if (count($spans[1]) > 1) {
                        $name = trim($spans[1][1]);
                    }
                }
                $categories[] = [
                    'pos' => $m[0][1],
                    'unique' => $m[1][0],
                    'name' => $name,
                ];
            }
        }

        return $categories;
    }

    public static function classifyContentSection(string $categoryName): string
    {
        $lower = strtolower($categoryName);
        foreach (self::CONTENT_SECTION_MARKERS as [$marker, $section]) {
            if (str_contains($lower, $marker)) {
                return $section;
            }
        }

        return 'other';
    }

    /**
     * Parse the detailed printable agenda (PRINT-AgendaDetailed) into agenda
     * items enriched with their subject, type, category, and the item body
     * content shown on the agenda page. Items are returned in document order,
     * each tagged with the category it belongs to.
     *
     * @return AgendaItemData[]
     */
    public static function parseDetailed(string $printHtml): array
    {
        if (trim($printHtml) === '') {
            return [];
        }

        $crawler = new Crawler;
        $crawler->addHtmlContent($printHtml, 'UTF-8');
        $items = [];

        $crawler->filter('div.container.item')->each(function (Crawler $node) use (&$items) {
            $rows = [];
            $node->filter('dl.row')->each(function (Crawler $dl) use (&$rows) {
                $dt = $dl->filter('dt');
                $dd = $dl->filter('dd');
                if ($dt->count() > 0 && $dd->count() > 0) {
                    $rows[trim($dt->first()->text(''))] = trim($dd->first()->text(''));
                }
            });

            [$order, $title] = self::splitOrder($rows['Subject'] ?? '');
            [$categoryOrder, $categoryName] = self::splitOrder($rows['Category'] ?? '');

            $body = $node->filter('div.itembody');
            $content = $body->count() > 0 ? trim($body->first()->html('')) : '';

            $attachments = FileLinkParser::parse($node->html(''));

            $items[] = new AgendaItemData(
                unique: '',
                order: $order,
                title: $title,
                hasAttachment: ! empty($attachments),
                attachments: $attachments,
                contentSection: self::classifyContentSection($categoryName),
                categoryName: $categoryName,
                categoryOrder: $categoryOrder,
                type: $rows['Type'] ?? '',
                content: $content,
            );
        });

        return $items;
    }

    /**
     * Split a leading order label ("1.", "A.") from a subject/category string.
     *
     * @return array{0: string, 1: string}  [order, remainder]
     */
    private static function splitOrder(string $value): array
    {
        $value = self::decode(trim($value));

        if (preg_match('/^([\pL\pN]+\.)\s+(.*)$/su', $value, $m)) {
            return [$m[1], trim($m[2])];
        }

        return ['', $value];
    }

    private static function decode(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
