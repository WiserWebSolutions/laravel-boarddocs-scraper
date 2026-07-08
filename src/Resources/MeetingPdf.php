<?php

namespace BoardDocsScraper\Resources;

use BoardDocsScraper\Data\SavedAttachment;
use BoardDocsScraper\Pdf\RenderedPdf;
use BoardDocsScraper\Support\OutputPaths;
use BoardDocsScraper\Support\Urls;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * The result of rendering a meeting agenda to PDF. Wraps the raw bytes plus the
 * structural metadata needed to build a search-index entry, and provides
 * convenient ways to persist or return the document.
 */
class MeetingPdf
{
    /**
     * @param  SavedAttachment[]  $attachments
     */
    public function __construct(
        protected RenderedPdf $rendered,
        public readonly string $filename,
        protected Meeting $meeting,
        protected array $config,
        protected array $attachments = [],
        protected string $agendaText = '',
    ) {
    }

    public function bytes(): string
    {
        return $this->rendered->bytes;
    }

    public function size(): int
    {
        return strlen($this->rendered->bytes);
    }

    public function pageCount(): int
    {
        return $this->rendered->pageCount;
    }

    public function meeting(): Meeting
    {
        return $this->meeting;
    }

    /**
     * @return SavedAttachment[]
     */
    public function attachments(): array
    {
        return $this->attachments;
    }

    /**
     * The default relative storage path, mirroring the original project layout:
     * {output.path}/{district}/{visibility}/{committee}/{YYYY-MM-DD}-Agenda.pdf
     */
    public function defaultPath(): string
    {
        $committee = $this->meeting->committee();

        return OutputPaths::meetingPath(
            $this->config,
            $committee->site()->name(),
            $committee->name,
            $this->meeting->date(),
        );
    }

    /**
     * Build the index.jsonl record for this meeting (same shape as the original
     * project), without needing to re-parse the generated PDF.
     */
    public function indexEntry(?string $relativePath = null): array
    {
        $committee = $this->meeting->committee();
        $relativePath ??= $this->defaultPath();

        return [
            'path' => OutputPaths::relativeToBase($this->config, $relativePath),
            'district' => Urls::districtIdFromSite($committee->site()->name()),
            'visibility' => $this->config['output']['visibility'] ?? 'Public',
            'committee' => $committee->name,
            'date' => $this->meeting->date(),
            'page_count' => $this->pageCount(),
            'agenda_text' => $this->agendaText,
            'attachments' => $this->rendered->attachmentToc(),
        ];
    }

    /**
     * Persist the PDF to the given (or configured) disk and path. Returns the
     * relative path written.
     */
    public function save(?string $path = null, ?string $disk = null): string
    {
        $path ??= $this->defaultPath();
        $disk ??= $this->config['output']['disk'] ?? 'local';

        Storage::disk($disk)->put($path, $this->rendered->bytes);

        return $path;
    }

    /**
     * Write the PDF to an absolute local filesystem path (outside Laravel disks).
     */
    public function writeTo(string $absolutePath): string
    {
        $dir = dirname($absolutePath);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($absolutePath, $this->rendered->bytes);

        return $absolutePath;
    }

    /**
     * A browser response (inline by default, or as an attachment download).
     */
    public function response(bool $download = false, ?string $filename = null): Response
    {
        $disposition = $download ? 'attachment' : 'inline';
        $name = $filename ?? $this->filename;

        return new Response($this->rendered->bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$name.'"',
            'Content-Length' => (string) $this->size(),
        ]);
    }
}
