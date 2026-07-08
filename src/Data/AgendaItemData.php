<?php

namespace BoardDocsScraper\Data;

/**
 * A single line item on an agenda. Mirrors the `AgendaItem` dataclass in the
 * Python exporter.
 *
 * The outline parser (BD-GetAgenda) populates identity fields (unique,
 * attachments) used for PDF assembly; the detailed parser (PRINT-AgendaDetailed)
 * additionally populates the subject/type and the item body content shown on
 * the agenda page (type, content, categoryOrder).
 */
class AgendaItemData
{
    /**
     * @param  AttachmentData[]  $attachments
     */
    public function __construct(
        public readonly string $unique,
        public readonly string $order,
        public readonly string $title,
        public readonly bool $hasAttachment,
        public array $attachments = [],
        public readonly string $contentSection = 'other',
        public readonly string $categoryName = '',
        public readonly string $categoryOrder = '',
        public readonly string $type = '',
        public readonly string $content = '',
    ) {
    }

    /**
     * The item's subject line. Alias of the title for readability when working
     * with the detailed agenda view.
     */
    public function subject(): string
    {
        return $this->title;
    }

    /**
     * A plain-text rendering of the item body content (HTML tags stripped).
     */
    public function contentText(): string
    {
        if ($this->content === '') {
            return '';
        }

        $text = html_entity_decode(strip_tags($this->content), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/[ \t]*\n[ \t]*(\n)?/', "\n", preg_replace('/[ \t]+/', ' ', $text)));
    }

    public function toArray(): array
    {
        return [
            'unique' => $this->unique,
            'order' => $this->order,
            'title' => $this->title,
            'has_attachment' => $this->hasAttachment,
            'attachments' => array_map(fn (AttachmentData $a) => $a->toArray(), $this->attachments),
            'content_section' => $this->contentSection,
            'category_name' => $this->categoryName,
            'category_order' => $this->categoryOrder,
            'type' => $this->type,
            'content' => $this->content,
        ];
    }
}
