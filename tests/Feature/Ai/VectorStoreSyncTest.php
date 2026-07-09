<?php

use BoardDocsScraper\Ai\VectorStoreSync;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Stores;

beforeEach(fn () => skipUnlessAiSdkInstalled());

function vectorSyncConfig(array $overrides = []): array
{
    return array_replace_recursive(config('boarddocs'), $overrides);
}

it('is disabled under the default jsonl driver', function () {
    $sync = new VectorStoreSync(vectorSyncConfig());

    expect($sync->enabled())->toBeFalse();
});

it('is disabled when the driver is vector but no store id is configured', function () {
    $sync = new VectorStoreSync(vectorSyncConfig([
        'ai' => ['search_driver' => 'vector', 'vector_store' => ['id' => null]],
    ]));

    expect($sync->enabled())->toBeFalse();
});

it('is enabled when the driver is vector and a store id is configured', function () {
    $sync = new VectorStoreSync(vectorSyncConfig([
        'ai' => ['search_driver' => 'vector', 'vector_store' => ['id' => 'store_123']],
    ]));

    expect($sync->enabled())->toBeTrue();
});

it('uploads the meeting pdf and returns the entry augmented with a vector_document_id', function () {
    Stores::fake();
    Storage::fake('local');
    Storage::disk('local')->put('boarddocs/pa-phoe/Public/Board/2024-01-08-Agenda.pdf', '%PDF-1.4 fake');

    $sync = new VectorStoreSync(vectorSyncConfig([
        'ai' => ['search_driver' => 'vector', 'vector_store' => ['id' => 'store_123']],
    ]));

    $entry = [
        'path' => 'pa-phoe/Public/Board/2024-01-08-Agenda.pdf',
        'committee' => 'Board',
        'date' => '2024-01-08',
        'page_count' => 3,
    ];

    $result = $sync->sync($entry, 'boarddocs/pa-phoe/Public/Board/2024-01-08-Agenda.pdf');

    expect($result['vector_document_id'])->toBeString()->not->toBeEmpty();
    expect($result)->toMatchArray($entry);

    Stores::get('store_123')->assertAdded(fn () => true);
});

it('removes the previously synced document before adding the refreshed one', function () {
    Stores::fake();
    Storage::fake('local');
    Storage::disk('local')->put('boarddocs/pa-phoe/Public/Board/2024-01-08-Agenda.pdf', '%PDF-1.4 fake');

    $sync = new VectorStoreSync(vectorSyncConfig([
        'ai' => ['search_driver' => 'vector', 'vector_store' => ['id' => 'store_123']],
    ]));

    $entry = ['path' => 'pa-phoe/Public/Board/2024-01-08-Agenda.pdf', 'committee' => 'Board', 'date' => '2024-01-08'];
    $relativePath = 'boarddocs/pa-phoe/Public/Board/2024-01-08-Agenda.pdf';

    $first = $sync->sync($entry, $relativePath);
    $second = $sync->sync($entry, $relativePath, $first);

    expect($first['vector_document_id'])->toBeString()->not->toBeEmpty();
    expect($second['vector_document_id'])->toBeString()->not->toBeEmpty();

    // The fake gateway derives a deterministic id from file identity, so
    // re-uploading identical content yields the same fake id — what matters
    // here is that the *previous* document was actually removed before re-adding.
    Stores::get('store_123')->assertRemoved($first['vector_document_id']);
});

it('does not attempt a removal on first sync when there is no previous document', function () {
    Stores::fake();
    Storage::fake('local');
    Storage::disk('local')->put('boarddocs/pa-phoe/Public/Board/2024-01-08-Agenda.pdf', '%PDF-1.4 fake');

    $sync = new VectorStoreSync(vectorSyncConfig([
        'ai' => ['search_driver' => 'vector', 'vector_store' => ['id' => 'store_123']],
    ]));

    $entry = ['path' => 'pa-phoe/Public/Board/2024-01-08-Agenda.pdf'];

    $sync->sync($entry, 'boarddocs/pa-phoe/Public/Board/2024-01-08-Agenda.pdf', null);

    Stores::get('store_123')->assertNotRemoved(fn () => true);
});
