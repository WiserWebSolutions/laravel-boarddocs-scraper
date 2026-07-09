<?php

namespace BoardDocsScraper\Index;

use BoardDocsScraper\Resources\MeetingPdf;
use Illuminate\Support\Facades\Storage;

/**
 * Maintains the JSONL search index (index.jsonl) over exported meeting PDFs.
 *
 * The index has the same shape as the original project so an assistant (or a
 * person) can search agendas without opening the PDFs:
 *   {"path","district","visibility","committee","date","page_count",
 *    "agenda_text","attachments":[{"title","page"}]}
 *
 * Entries are kept up to date incrementally: existing lines are loaded, entries
 * for freshly exported meetings are merged in (keyed by "path"), and the file is
 * rewritten sorted. Because entries are built from in-memory render metadata,
 * no PDF re-parsing is required.
 */
class IndexBuilder
{
    /** @var array<string, array> path => entry */
    protected array $entries = [];

    public function __construct(protected array $config)
    {
    }

    /**
     * Load any existing index from disk into memory.
     */
    public function load(): static
    {
        $contents = $this->storage()->exists($this->path())
            ? (string) $this->storage()->get($this->path())
            : '';

        foreach (preg_split('/\r?\n/', $contents) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $entry = json_decode($line, true);
            if (is_array($entry) && isset($entry['path'])) {
                $this->entries[$entry['path']] = $entry;
            }
        }

        return $this;
    }

    public function put(array $entry): static
    {
        if (isset($entry['path'])) {
            $this->entries[$entry['path']] = $entry;
        }

        return $this;
    }

    /**
     * The previously loaded entry for a path, if any (e.g. to carry forward a
     * "vector_document_id" before it's overwritten by a fresh entry).
     */
    public function get(string $path): ?array
    {
        return $this->entries[$path] ?? null;
    }

    public function putMeeting(MeetingPdf $pdf, ?string $relativePath = null): static
    {
        return $this->put($pdf->indexEntry($relativePath));
    }

    /**
     * @return array<int, array>
     */
    public function all(): array
    {
        $entries = array_values($this->entries);

        usort($entries, function ($a, $b) {
            return [$a['district'] ?? '', $a['visibility'] ?? '', $a['committee'] ?? '', $a['date'] ?? '']
                <=> [$b['district'] ?? '', $b['visibility'] ?? '', $b['committee'] ?? '', $b['date'] ?? ''];
        });

        return $entries;
    }

    /**
     * Write the index to disk (one JSON object per line) and return its path.
     */
    public function save(): string
    {
        $lines = array_map(
            fn ($e) => json_encode($e, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $this->all()
        );

        $this->storage()->put($this->path(), implode("\n", $lines).(empty($lines) ? '' : "\n"));

        return $this->path();
    }

    public function count(): int
    {
        return count($this->entries);
    }

    public function path(): string
    {
        return $this->config['output']['index'] ?? 'boarddocs/index.jsonl';
    }

    protected function storage()
    {
        return Storage::disk($this->config['output']['disk'] ?? 'local');
    }
}
