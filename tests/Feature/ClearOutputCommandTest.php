<?php

use BoardDocsScraper\Support\OutputPaths;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

/**
 * fakeBoardDocsForArchive() is declared globally in ArchiveTest.php and
 * reused here.
 */
it('reports there is nothing to clear when nothing has been built', function () {
    Storage::fake('local');

    $exit = Artisan::call('boarddocs:clear-output', ['--site' => 'pa/phoe', '--force' => true]);

    expect($exit)->toBe(0);
    expect(Artisan::output())->toContain('already clear');
});

it('deletes a single site\'s generated pdfs and prunes its index entries', function () {
    Storage::fake('local');
    fakeBoardDocsForArchive();

    Artisan::call('boarddocs:scan', ['--site' => 'pa/phoe']);

    $config = app('boarddocs')->config();
    $pdfPath = OutputPaths::meetingPath($config, 'pa/phoe', 'Board of School Directors', '2024-01-08');
    Storage::disk('local')->assertExists($pdfPath);

    $index = app('boarddocs')->indexBuilder()->load();
    expect($index->count())->toBe(2);

    $exit = Artisan::call('boarddocs:clear-output', ['--site' => 'pa/phoe', '--force' => true]);

    expect($exit)->toBe(0);
    expect(Artisan::output())->toContain('2 index entries removed');
    Storage::disk('local')->assertMissing($pdfPath);

    $index = app('boarddocs')->indexBuilder()->load();
    expect($index->count())->toBe(0);

    // The archive is untouched, so a rebuild can regenerate the pdf.
    Artisan::call('boarddocs:build', ['--site' => 'pa/phoe']);
    Storage::disk('local')->assertExists($pdfPath);
});

it('asks for confirmation and aborts without deleting anything when declined', function () {
    Storage::fake('local');
    fakeBoardDocsForArchive();

    Artisan::call('boarddocs:scan', ['--site' => 'pa/phoe']);

    $config = app('boarddocs')->config();
    $pdfPath = OutputPaths::meetingPath($config, 'pa/phoe', 'Board of School Directors', '2024-01-08');

    $this->artisan('boarddocs:clear-output', ['--site' => 'pa/phoe'])
        ->expectsConfirmation('This will permanently delete the generated PDFs and index entries for boarddocs-private/pa-phoe. Continue?', 'no')
        ->assertFailed();

    Storage::disk('local')->assertExists($pdfPath);
});

it('clears every site\'s generated pdfs and the whole index with --all', function () {
    Storage::fake('local');
    fakeBoardDocsForArchive();

    Artisan::call('boarddocs:scan', ['--site' => 'pa/phoe']);

    $config = app('boarddocs')->config();
    $pdfPath = OutputPaths::meetingPath($config, 'pa/phoe', 'Board of School Directors', '2024-01-08');
    Storage::disk('local')->assertExists($pdfPath);

    $exit = Artisan::call('boarddocs:clear-output', ['--all' => true, '--force' => true]);

    expect($exit)->toBe(0);
    Storage::disk('local')->assertMissing($pdfPath);

    $index = app('boarddocs')->indexBuilder()->load();
    expect($index->count())->toBe(0);
});
