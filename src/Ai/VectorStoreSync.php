<?php

namespace BoardDocsScraper\Ai;

use Laravel\Ai\Files\Document;
use Laravel\Ai\Stores;

/**
 * Uploads exported meeting PDFs into a Laravel AI SDK vector store so
 * FileSearch (see BoardDocsAgent) can retrieve them semantically, as an
 * alternative to the local IndexSearcher keyword search.
 *
 * Storing "path"/"committee"/"date"/"page_count" as metadata on each document
 * lets an agent map a FileSearch citation back to GetMeetingTool's "path".
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
        $store = Stores::get($this->storeId(), $this->config['ai']['vector_store']['provider'] ?? null);

        if (! empty($previous['vector_document_id'])) {
            $store->remove($previous['vector_document_id'], deleteFile: true);
        }

        $document = Document::fromStorage($relativePath, disk: $this->config['output']['disk'] ?? 'local');

        $added = $store->add($document, metadata: [
            'path' => $entry['path'] ?? null,
            'district' => $entry['district'] ?? null,
            'committee' => $entry['committee'] ?? null,
            'date' => $entry['date'] ?? null,
            'page_count' => $entry['page_count'] ?? null,
        ]);

        return array_merge($entry, ['vector_document_id' => $added->id]);
    }
}
