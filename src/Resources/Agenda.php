<?php

namespace BoardDocsScraper\Resources;

use BoardDocsScraper\Client\BoardDocsClient;
use BoardDocsScraper\Data\AgendaItemData;
use BoardDocsScraper\Parsing\AgendaParser;
use BoardDocsScraper\Pdf\MeetingDocument;
use BoardDocsScraper\Pdf\PdfManager;
use BoardDocsScraper\Support\AttachmentCollector;
use Illuminate\Support\Collection;

/**
 * A fluent builder around a single meeting's agenda. Terminal methods fetch the
 * printable agenda, optionally download attachments, and render a PDF.
 *
 * Example:
 *   $committee->agenda()->withAttachments()->toPdf()->save();
 */
class Agenda
{
    protected bool $withAttachments = false;

    protected ?string $printHtml = null;

    /** @var AgendaItemData[]|null Detailed items (subject + content) from the print agenda. */
    protected ?array $items = null;

    /** @var AgendaItemData[]|null Outline items (unique ids) from BD-GetAgenda, used for PDF assembly. */
    protected ?array $outlineItems = null;

    public function __construct(
        protected Meeting $meeting,
        protected BoardDocsClient $client,
        protected array $config,
    ) {
        // Default the self-contained behaviour to the package config.
        $this->withAttachments = (bool) ($config['pdf']['self_contained'] ?? true);
    }

    public function meeting(): Meeting
    {
        return $this->meeting;
    }

    /**
     * Include (download + merge) attachments when rendering the PDF.
     */
    public function withAttachments(bool $flag = true): static
    {
        $this->withAttachments = $flag;

        return $this;
    }

    public function withoutAttachments(): static
    {
        $this->withAttachments = false;

        return $this;
    }

    /**
     * The raw printable agenda HTML (PRINT-AgendaDetailed), cached per instance.
     */
    public function html(): string
    {
        return $this->printHtml ??= $this->client->fetchPrintAgendaHtml(
            $this->meeting->unique(),
            $this->meeting->committee()->committeeId,
        );
    }

    /**
     * All agenda items in document order (categories skipped), each carrying its
     * subject and the item body content shown on the agenda page. Parsed from
     * the detailed printable agenda.
     *
     * @return Collection<int, AgendaItemData>
     */
    public function items(): Collection
    {
        $this->items ??= AgendaParser::parseDetailed($this->html());

        return collect($this->items);
    }

    /**
     * The agenda broken into its ordered categories (e.g. "A. OPENING OF
     * MEETING"), each exposing its items() with subject and content.
     *
     * @return Collection<int, AgendaCategory>
     */
    public function categories(): Collection
    {
        $groups = [];

        foreach ($this->items() as $item) {
            $key = $item->categoryOrder.'|'.$item->categoryName;
            $groups[$key] ??= [
                'order' => $item->categoryOrder,
                'name' => $item->categoryName,
                'items' => [],
            ];
            $groups[$key]['items'][] = $item;
        }

        return collect($groups)
            ->map(fn (array $group) => new AgendaCategory(
                $group['order'],
                $group['name'],
                collect($group['items']),
            ))
            ->values();
    }

    /**
     * Outline items from BD-GetAgenda. These carry the BoardDocs unique ids and
     * attachment flags needed to collect per-item files during PDF assembly.
     *
     * @return Collection<int, AgendaItemData>
     */
    protected function outlineItems(): Collection
    {
        $this->outlineItems ??= $this->client->fetchAgendaItems(
            $this->meeting->unique(),
            $this->meeting->committee()->committeeId,
        );

        return collect($this->outlineItems);
    }

    /**
     * A plain-text rendering of the agenda summary (useful for previews / search).
     */
    public function text(): string
    {
        $html = preg_replace('/<(script|style|noscript)\b[^>]*>[\s\S]*?<\/\1>/i', ' ', $this->html());
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/[ \t]*\n[ \t]*(\n)?/', "\n", preg_replace('/[ \t]+/', ' ', $text)));
    }

    /**
     * Attachment metadata referenced by the agenda (no files downloaded).
     *
     * @return Collection<int, \BoardDocsScraper\Data\AttachmentData>
     */
    public function attachments(): Collection
    {
        $committeeId = $this->meeting->committee()->committeeId;
        $all = \BoardDocsScraper\Parsing\FileLinkParser::parse($this->html());
        $seen = [];
        $out = [];

        foreach ($all as $att) {
            $seen[$att->unique] = true;
            $out[] = $att;
        }

        foreach ($this->outlineItems() as $item) {
            if (! $item->hasAttachment) {
                continue;
            }
            $files = $this->client->fetchItemAttachments($item->unique, $committeeId);
            if (empty($files)) {
                $files = $item->attachments;
            }
            foreach ($files as $att) {
                if (! isset($seen[$att->unique])) {
                    $seen[$att->unique] = true;
                    $out[] = $att;
                }
            }
        }

        return collect($out);
    }

    /**
     * Render the (optionally self-contained) meeting PDF.
     */
    public function toPdf(): MeetingPdf
    {
        $printHtml = $this->html();
        $committee = $this->meeting->committee();

        $saved = [];
        if ($this->withAttachments) {
            $saved = (new AttachmentCollector($this->client))->collect(
                $this->meeting->data(),
                $committee->committeeId,
                $printHtml,
                $this->outlineItems()->all(),
            );
        }

        $document = new MeetingDocument(
            agendaHtml: $printHtml,
            baseUrl: $this->client->baseUrl(),
            site: $this->client->site(),
            savedAttachments: $saved,
            options: $this->config['pdf'],
            title: $this->meeting->name() !== '' ? $this->meeting->name() : 'Agenda',
            date: $this->meeting->date(),
            committee: $committee->name,
            filename: $this->meeting->date().'-Agenda.pdf',
        );

        $rendered = (new PdfManager($this->config))->render($document);

        return new MeetingPdf($rendered, $document->filename, $this->meeting, $this->config, $saved, $this->text());
    }

    /**
     * Render and persist the PDF to the configured (or given) disk. Returns the
     * relative storage path.
     */
    public function save(?string $path = null, ?string $disk = null): string
    {
        return $this->toPdf()->save($path, $disk);
    }
}
