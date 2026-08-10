<?php

namespace BoardDocsScraper\Resources;

use BoardDocsScraper\Client\BoardDocsClient;
use BoardDocsScraper\Data\AgendaItemData;
use BoardDocsScraper\Data\SavedAttachment;
use BoardDocsScraper\Exceptions\BoardDocsException;
use BoardDocsScraper\Parsing\AgendaParser;
use BoardDocsScraper\Pdf\Assembler;
use BoardDocsScraper\Pdf\MeetingDocument;
use BoardDocsScraper\Pdf\PdfManager;
use BoardDocsScraper\Support\Archive;
use BoardDocsScraper\Support\AttachmentCollector;
use BoardDocsScraper\Support\OutputPaths;
use BoardDocsScraper\Support\Urls;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

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

    protected ?Archive $archive = null;

    protected bool $archiveResolved = false;

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
        if ($this->printHtml !== null) {
            return $this->printHtml;
        }

        $committee = $this->meeting->committee();
        $archive = $this->archive();

        $archived = $archive?->getAgendaHtml($committee->site()->name(), $committee->name, $this->meeting->date());
        if ($archived !== null) {
            return $this->printHtml = $archived;
        }

        $html = $this->client->fetchPrintAgendaHtml($this->meeting->unique(), $committee->committeeId);

        $archive?->putAgendaHtml($committee->site()->name(), $committee->name, $this->meeting->date(), $html);

        return $this->printHtml = $html;
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
     * Render the (optionally self-contained) meeting PDF, fetching (or
     * reusing from the archive) whatever agenda HTML/attachments are needed.
     */
    public function toPdf(): MeetingPdf
    {
        $printHtml = $this->html();
        $committee = $this->meeting->committee();

        $saved = [];
        if ($this->withAttachments) {
            $saved = $this->collectAttachments($committee, $printHtml);

            if ($this->config['output']['save_attachments'] ?? true) {
                $this->persistAttachments($saved);
            }
        }

        return $this->renderPdf($printHtml, $committee, $saved);
    }

    /**
     * Render the meeting PDF strictly from the archive — never making a live
     * BoardDocs request. Throws if the archive is missing this meeting's
     * agenda HTML, its attachment manifest, or an individual attachment file;
     * run `boarddocs:prefetch` first to fill the gap. Used by
     * `boarddocs:build`.
     */
    public function toPdfFromArchive(): MeetingPdf
    {
        $committee = $this->meeting->committee();
        $printHtml = $this->archivedHtmlOrFail($committee);

        $saved = [];
        if ($this->withAttachments) {
            $saved = $this->archivedAttachmentsOrFail($committee);

            if ($this->config['output']['save_attachments'] ?? true) {
                $this->persistAttachments($saved);
            }
        }

        return $this->renderPdf($printHtml, $committee, $saved);
    }

    /**
     * Render and persist the PDF to the configured (or given) disk. Returns the
     * relative storage path.
     */
    public function save(?string $path = null, ?string $disk = null): string
    {
        return $this->toPdf()->save($path, $disk);
    }

    /**
     * @param  \BoardDocsScraper\Data\SavedAttachment[]  $saved
     */
    protected function renderPdf(string $printHtml, Committee $committee, array $saved): MeetingPdf
    {
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
     * html(), but fails instead of falling back to a live BoardDocs request.
     */
    protected function archivedHtmlOrFail(Committee $committee): string
    {
        if ($this->printHtml !== null) {
            return $this->printHtml;
        }

        $archive = $this->archive();
        $html = $archive?->getAgendaHtml($committee->site()->name(), $committee->name, $this->meeting->date());
        if ($html === null) {
            throw new BoardDocsException(
                "Agenda HTML for {$this->meeting->date()} is missing from the archive — run boarddocs:prefetch first."
            );
        }

        return $this->printHtml = $html;
    }

    /**
     * collectAttachments(), but fails instead of falling back to a live
     * BoardDocs request for a manifest entry, or a meeting never prefetched.
     *
     * @return \BoardDocsScraper\Data\SavedAttachment[]
     */
    protected function archivedAttachmentsOrFail(Committee $committee): array
    {
        $archive = $this->archive();
        $site = $committee->site()->name();
        $date = $this->meeting->date();

        $manifest = $archive?->getManifest($site, $committee->name, $date);
        if ($manifest === null) {
            throw new BoardDocsException(
                "Attachments for {$date} are missing from the archive — run boarddocs:prefetch first."
            );
        }

        $tempDir = $this->makeTempDir();
        $saved = [];

        foreach ($manifest as $record) {
            $bookmark = (string) ($record['bookmark'] ?? '');
            if ($bookmark === '') {
                continue;
            }

            $localPath = $tempDir.DIRECTORY_SEPARATOR.$bookmark;

            if (! $archive->copyAttachmentTo($site, $committee->name, $date, $bookmark, $localPath)) {
                throw new BoardDocsException(
                    "Attachment '{$bookmark}' for {$date} is missing from the archive — run boarddocs:prefetch first."
                );
            }

            $saved[] = new SavedAttachment(
                bookmark: $bookmark,
                path: $localPath,
                resolvedUrl: (string) ($record['resolvedUrl'] ?? $record['href'] ?? ''),
                href: (string) ($record['href'] ?? ''),
                fileUnique: (string) ($record['fileUnique'] ?? ''),
                itemUnique: (string) ($record['itemUnique'] ?? ''),
            );
        }

        return $saved;
    }

    /**
     * Fetch (or reuse from the archive) this meeting's agenda HTML and
     * attachment files without rendering a PDF, so a later toPdf() call can
     * build the document entirely from disk. Used by `boarddocs:prefetch` to
     * warm the archive ahead of a scan while BoardDocs is still reachable.
     *
     * @return \BoardDocsScraper\Data\SavedAttachment[]
     */
    public function cacheAttachments(): array
    {
        $printHtml = $this->html();
        $saved = $this->collectAttachments($this->meeting->committee(), $printHtml);

        Assembler::cleanup(array_map(fn (SavedAttachment $a) => $a->path, $saved));

        return $saved;
    }

    /**
     * Collect this meeting's attachments, preferring the archive over a live
     * fetch whenever a manifest already exists for it (a prior run persisted
     * one). Any bookmark listed in an existing manifest whose bytes are
     * missing from the archive is downloaded fresh and added back to it — the
     * only case that reconnects to BoardDocs once a manifest exists.
     */
    protected function collectAttachments(Committee $committee, string $printHtml): array
    {
        $archive = $this->archive();
        $site = $committee->site()->name();
        $date = $this->meeting->date();

        $manifest = $archive?->getManifest($site, $committee->name, $date);
        if ($manifest !== null) {
            return $this->rehydrateAttachments($archive, $site, $committee->name, $date, $manifest);
        }

        $saved = (new AttachmentCollector($this->client))->collect(
            $this->meeting->data(),
            $committee->committeeId,
            $printHtml,
            $this->outlineItems()->all(),
        );

        if ($archive !== null) {
            $this->archiveCollectedAttachments($archive, $site, $committee->name, $date, $saved);
        }

        return $saved;
    }

    /**
     * @return \BoardDocsScraper\Data\SavedAttachment[]
     */
    protected function rehydrateAttachments(Archive $archive, string $site, string $committeeName, string $date, array $manifest): array
    {
        $tempDir = $this->makeTempDir();
        $saved = [];

        foreach ($manifest as $record) {
            $bookmark = (string) ($record['bookmark'] ?? '');
            if ($bookmark === '') {
                continue;
            }

            $localPath = $tempDir.DIRECTORY_SEPARATOR.$bookmark;

            if (! $archive->copyAttachmentTo($site, $committeeName, $date, $bookmark, $localPath)) {
                $href = Urls::resolveAttachmentUrl((string) ($record['href'] ?? ''), $this->client->baseUrl());
                $size = $this->client->downloadToFile($href, $localPath);
                if ($size === 0) {
                    @unlink($localPath);
                    throw new BoardDocsException("Empty download for archived attachment '{$bookmark}' ({$href}).");
                }
                $archive->putAttachmentFile($site, $committeeName, $date, $bookmark, $localPath);
            }

            $saved[] = new SavedAttachment(
                bookmark: $bookmark,
                path: $localPath,
                resolvedUrl: (string) ($record['resolvedUrl'] ?? $record['href'] ?? ''),
                href: (string) ($record['href'] ?? ''),
                fileUnique: (string) ($record['fileUnique'] ?? ''),
                itemUnique: (string) ($record['itemUnique'] ?? ''),
            );
        }

        return $saved;
    }

    /**
     * @param  \BoardDocsScraper\Data\SavedAttachment[]  $saved
     */
    protected function archiveCollectedAttachments(Archive $archive, string $site, string $committeeName, string $date, array $saved): void
    {
        $manifest = [];
        foreach ($saved as $attachment) {
            /** @var SavedAttachment $attachment */
            $archive->putAttachmentFile($site, $committeeName, $date, $attachment->bookmark, $attachment->path);
            $manifest[] = [
                'bookmark' => $attachment->bookmark,
                'href' => $attachment->href,
                'resolvedUrl' => $attachment->resolvedUrl,
                'fileUnique' => $attachment->fileUnique,
                'itemUnique' => $attachment->itemUnique,
            ];
        }

        $archive->putManifest($site, $committeeName, $date, $manifest);
    }

    protected function makeTempDir(): string
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'bdscraper_'.bin2hex(random_bytes(5));
        if (! is_dir($dir) && ! mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw new BoardDocsException("Could not create temp directory '{$dir}' for archived attachment downloads.");
        }

        return $dir;
    }

    protected function archive(): ?Archive
    {
        if (! $this->archiveResolved) {
            $this->archiveResolved = true;
            $this->archive = ($this->config['archive']['enabled'] ?? true) ? new Archive($this->config) : null;
        }

        return $this->archive;
    }

    /**
     * Archive each attachment's raw downloaded bytes (unmodified, as
     * originally downloaded), before the PDF render pipeline deletes its temp
     * copies. This is intentionally independent of whether the file also
     * gets merged into the PDF, so the original source document is always
     * available to refer back to (e.g. to open a spreadsheet natively rather
     * than its embedded copy).
     *
     * @param  \BoardDocsScraper\Data\SavedAttachment[]  $saved
     */
    protected function persistAttachments(array $saved): void
    {
        if (empty($saved)) {
            return;
        }

        $committee = $this->meeting->committee();
        $dir = OutputPaths::attachmentsPath(
            $this->config,
            $committee->site()->name(),
            $committee->name,
            $this->meeting->date(),
        );

        $disk = Storage::disk($this->config['archive']['disk'] ?? ($this->config['output']['disk'] ?? 'local'));

        foreach ($saved as $attachment) {
            $disk->put($dir.'/'.$attachment->bookmark, file_get_contents($attachment->path));
        }
    }
}
