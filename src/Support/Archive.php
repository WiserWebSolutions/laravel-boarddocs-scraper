<?php

namespace BoardDocsScraper\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Persists everything downloaded from BoardDocs, unmodified — the raw
 * print-agenda HTML and attachment files — under boarddocs.archive.path
 * (default "boarddocs-public"), kept separate from output.path, which only
 * holds what we produce ourselves (the merged PDF + index).
 *
 * For a meeting that hasn't been exported yet, this doubles as a resilience
 * cache: it lets a scan finish building that meeting's PDF from disk if
 * BoardDocs starts returning 403s partway through a run — reconnecting only
 * for whatever individual file is missing from the archive. Agenda checks it
 * before making a live request for exactly that reason; once a PDF has
 * actually been exported, the cache-hit shortcut no longer matters for that
 * meeting, but the archived files themselves are kept regardless.
 */
class Archive
{
    public function __construct(protected array $config)
    {
    }

    public function enabled(): bool
    {
        return (bool) ($this->config['archive']['enabled'] ?? true);
    }

    public function getAgendaHtml(string $site, string $committeeName, string $date): ?string
    {
        $path = OutputPaths::archiveAgendaHtmlPath($this->config, $site, $committeeName, $date);

        return $this->disk()->exists($path) ? $this->disk()->get($path) : null;
    }

    public function putAgendaHtml(string $site, string $committeeName, string $date, string $html): void
    {
        $this->disk()->put(OutputPaths::archiveAgendaHtmlPath($this->config, $site, $committeeName, $date), $html);
    }

    /**
     * @return array<int, array{bookmark: string, href: string, resolvedUrl: string, fileUnique: string, itemUnique: string}>|null
     */
    public function getManifest(string $site, string $committeeName, string $date): ?array
    {
        $path = $this->manifestPath($site, $committeeName, $date);
        if (! $this->disk()->exists($path)) {
            return null;
        }

        $decoded = json_decode((string) $this->disk()->get($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<int, array{bookmark: string, href: string, resolvedUrl: string, fileUnique: string, itemUnique: string}>  $records
     */
    public function putManifest(string $site, string $committeeName, string $date, array $records): void
    {
        $this->disk()->put(
            $this->manifestPath($site, $committeeName, $date),
            json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    public function hasAttachmentFile(string $site, string $committeeName, string $date, string $bookmark): bool
    {
        return $this->disk()->exists($this->attachmentFilePath($site, $committeeName, $date, $bookmark));
    }

    /**
     * Copy the archived attachment bytes to a real filesystem path so PDF
     * assembly (which needs an on-disk file for Fpdi::setSourceFile) can use
     * it exactly like a freshly downloaded attachment. Returns false without
     * writing anything if the file isn't archived.
     */
    public function copyAttachmentTo(string $site, string $committeeName, string $date, string $bookmark, string $destination): bool
    {
        if (! $this->hasAttachmentFile($site, $committeeName, $date, $bookmark)) {
            return false;
        }

        file_put_contents($destination, $this->disk()->get($this->attachmentFilePath($site, $committeeName, $date, $bookmark)));

        return true;
    }

    public function putAttachmentFile(string $site, string $committeeName, string $date, string $bookmark, string $localPath): void
    {
        $this->disk()->put(
            $this->attachmentFilePath($site, $committeeName, $date, $bookmark),
            file_get_contents($localPath),
        );
    }

    protected function disk(): Filesystem
    {
        return Storage::disk($this->config['archive']['disk'] ?? ($this->config['output']['disk'] ?? 'local'));
    }

    protected function manifestPath(string $site, string $committeeName, string $date): string
    {
        return OutputPaths::archiveAttachmentsPath($this->config, $site, $committeeName, $date).'/manifest.json';
    }

    protected function attachmentFilePath(string $site, string $committeeName, string $date, string $bookmark): string
    {
        return OutputPaths::archiveAttachmentsPath($this->config, $site, $committeeName, $date).'/'.$bookmark;
    }
}
