<?php

namespace BoardDocsScraper\Ai;

use Laravel\Ai\Files\Document;
use Laravel\Ai\Stores;

/**
 * Uploads exported meeting PDFs (and, via syncDocument(), any other file —
 * raw agenda HTML, individual raw attachments) into a Laravel AI SDK vector
 * store so FileSearch (see BoardDocsAgent) can retrieve them semantically, as
 * an alternative to the local IndexSearcher keyword search.
 *
 * Storing "path"/"committee"/"date"/"page_count" as metadata on each document
 * lets an agent map a FileSearch citation back to GetMeetingTool's "path".
 * boarddocs:sync-vector is what actually drives this against a built archive.
 */
class VectorStoreSync
{
    public function __construct(protected array $config)
    {
    }

    public function enabled(): bool
    {
        return ($this->config['ai']['search_driver'] ?? 'jsonl') === 'vector'
            && ! empty($this->storeId());
    }

    public function storeId(): ?string
    {
        return $this->config['ai']['vector_store']['id'] ?? null;
    }

    /**
     * Upload the meeting PDF at $relativePath into the configured vector
     * store, replacing any document previously synced for this same path
     * (per $previous's "vector_document_id"), and return $entry augmented
     * with the new "vector_document_id".
     */
    public function sync(array $entry, string $relativePath, ?array $previous = null): array
    {
        $id = $this->syncDocument($relativePath, $this->config['output']['disk'] ?? 'local', [
            'path' => $entry['path'] ?? null,
            'district' => $entry['district'] ?? null,
            'committee' => $entry['committee'] ?? null,
            'date' => $entry['date'] ?? null,
            'page_count' => $entry['page_count'] ?? null,
        ], $previous['vector_document_id'] ?? null);

        return array_merge($entry, ['vector_document_id' => $id]);
    }

    /**
     * Upload the file at $relativePath (on $disk) into the configured vector
     * store with the given $metadata, replacing $previousDocumentId if one is
     * given, and return the new document's id. Used for anything beyond the
     * merged meeting PDF — e.g. the raw agenda HTML or an individual raw
     * attachment file — so each can carry its own metadata (see
     * BoardDocsScraper\Console\SyncVectorCommand).
     */
    public function syncDocument(string $relativePath, ?string $disk, array $metadata, ?string $previousDocumentId = null): string
    {
        $store = Stores::get($this->storeId(), $this->config['ai']['vector_store']['provider'] ?? null);

        if (! empty($previousDocumentId)) {
            $store->remove($previousDocumentId, deleteFile: true);
        }

        $document = Document::fromStorage($relativePath, disk: $disk);

        $added = $store->add($document, metadata: $metadata);

        return $added->id;
    }
}
