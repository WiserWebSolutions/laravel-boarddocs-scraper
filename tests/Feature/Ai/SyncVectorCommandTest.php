<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Stores;

/**
 * fakeBoardDocsForArchive() and samplePdf() are declared globally in
 * ArchiveTest.php / FluentApiTest.php and reused here.
 */
beforeEach(fn () => skipUnlessAiSdkInstalled());

it('fails with a clear message when vector sync is not enabled', function () {
    Storage::fake('local');

    $exit = Artisan::call('boarddocs:sync-vector', ['--site' => 'pa/phoe']);

    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('Vector sync is not enabled');
});

it('uploads the built pdf, raw agenda html, and raw attachment for a built meeting', function () {
    Storage::fake('local');
    Stores::fake();
    fakeBoardDocsForArchive();

    config(['boarddocs.ai.search_driver' => 'vector', 'boarddocs.ai.vector_store.id' => 'store_123']);

    Artisan::call('boarddocs:scan', ['--site' => 'pa/phoe']);

    $exit = Artisan::call('boarddocs:sync-vector', ['--site' => 'pa/phoe']);

    expect($exit)->toBe(0);
    $output = Artisan::output();
    expect($output)
        ->toContain('uploaded 2024-01-08 agenda PDF')
        ->toContain('uploaded 2024-01-08 raw agenda HTML')
        ->toContain('uploaded 2024-01-08 attachment: Personnel Report.pdf')
        ->toContain('uploaded 2023-12-04 agenda PDF')
        ->toContain('uploaded 2023-12-04 raw agenda HTML');

    $config = app('boarddocs')->config();
    $indexPath = $config['output']['index'] ?? 'boarddocs/index.jsonl';
    $lines = array_filter(explode("\n", (string) Storage::disk('local')->get($indexPath)));
    $entries = array_map(fn ($l) => json_decode($l, true), $lines);
    $entry = collect($entries)->firstWhere('date', '2024-01-08');

    expect($entry['vector_document_id'])->not->toBeEmpty();
    expect($entry['vector_agenda_html_id'])->not->toBeEmpty();
    expect($entry['vector_attachment_ids']['Personnel Report.pdf'])->not->toBeEmpty();
});

it('skips a meeting that has not been built yet, without contacting the store', function () {
    Storage::fake('local');
    Stores::fake();
    fakeBoardDocsForArchive();

    config(['boarddocs.ai.search_driver' => 'vector', 'boarddocs.ai.vector_store.id' => 'store_123']);

    Artisan::call('boarddocs:prefetch', ['--site' => 'pa/phoe']);

    $exit = Artisan::call('boarddocs:sync-vector', ['--site' => 'pa/phoe']);

    expect($exit)->toBe(0);
    expect(Artisan::output())
        ->toContain('not built yet, run boarddocs:build first')
        ->toContain('uploaded=0');
});

it('does not re-upload already-synced files on a second run without --force', function () {
    Storage::fake('local');
    Stores::fake();
    fakeBoardDocsForArchive();

    config(['boarddocs.ai.search_driver' => 'vector', 'boarddocs.ai.vector_store.id' => 'store_123']);

    Artisan::call('boarddocs:scan', ['--site' => 'pa/phoe']);
    Artisan::call('boarddocs:sync-vector', ['--site' => 'pa/phoe']);

    $exit = Artisan::call('boarddocs:sync-vector', ['--site' => 'pa/phoe']);

    expect($exit)->toBe(0);
    expect(Artisan::output())->toContain('uploaded=0');
});

it('re-uploads already-synced files when --force is passed', function () {
    Storage::fake('local');
    Stores::fake();
    fakeBoardDocsForArchive();

    config(['boarddocs.ai.search_driver' => 'vector', 'boarddocs.ai.vector_store.id' => 'store_123']);

    Artisan::call('boarddocs:scan', ['--site' => 'pa/phoe']);
    Artisan::call('boarddocs:sync-vector', ['--site' => 'pa/phoe']);

    $exit = Artisan::call('boarddocs:sync-vector', ['--site' => 'pa/phoe', '--force' => true]);

    expect($exit)->toBe(0);
    expect(Artisan::output())->toContain('uploaded 2024-01-08 agenda PDF');
});
