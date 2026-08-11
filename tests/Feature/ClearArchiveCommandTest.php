<?php

use BoardDocsScraper\Support\OutputPaths;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

/**
 * fakeBoardDocsForArchive() is declared globally in ArchiveTest.php and
 * reused here.
 */
it('reports there is nothing to clear when the archive is empty', function () {
    Storage::fake('local');

    $exit = Artisan::call('boarddocs:clear-archive', ['--site' => 'pa/phoe', '--force' => true]);

    expect($exit)->toBe(0);
    expect(Artisan::output())->toContain('already clear');
});

it('deletes a single site\'s archived agenda html, attachments, and meeting lists', function () {
    Storage::fake('local');
    fakeBoardDocsForArchive();

    Artisan::call('boarddocs:prefetch', ['--site' => 'pa/phoe']);

    $config = app('boarddocs')->config();
    $htmlPath = OutputPaths::archiveAgendaHtmlPath($config, 'pa/phoe', 'Board of School Directors', '2024-01-08');
    Storage::disk('local')->assertExists($htmlPath);

    $exit = Artisan::call('boarddocs:clear-archive', ['--site' => 'pa/phoe', '--force' => true]);

    expect($exit)->toBe(0);
    Storage::disk('local')->assertMissing($htmlPath);

    // A rebuild has nothing to read anymore.
    $statusExit = Artisan::call('boarddocs:status', ['--site' => 'pa/phoe']);
    expect($statusExit)->toBe(1);
    expect(Artisan::output())->toContain('Nothing archived yet');
});

it('asks for confirmation and aborts without deleting anything when declined', function () {
    Storage::fake('local');
    fakeBoardDocsForArchive();

    Artisan::call('boarddocs:prefetch', ['--site' => 'pa/phoe']);

    $config = app('boarddocs')->config();
    $htmlPath = OutputPaths::archiveAgendaHtmlPath($config, 'pa/phoe', 'Board of School Directors', '2024-01-08');

    $this->artisan('boarddocs:clear-archive', ['--site' => 'pa/phoe'])
        ->expectsConfirmation('This will permanently delete the archived data at boarddocs-public/pa-phoe. Continue?', 'no')
        ->assertFailed();

    Storage::disk('local')->assertExists($htmlPath);
});

it('clears every site\'s archived data with --all', function () {
    Storage::fake('local');
    fakeBoardDocsForArchive();

    Artisan::call('boarddocs:prefetch', ['--site' => 'pa/phoe']);

    $config = app('boarddocs')->config();
    $htmlPath = OutputPaths::archiveAgendaHtmlPath($config, 'pa/phoe', 'Board of School Directors', '2024-01-08');
    Storage::disk('local')->assertExists($htmlPath);

    $exit = Artisan::call('boarddocs:clear-archive', ['--all' => true, '--force' => true]);

    expect($exit)->toBe(0);
    Storage::disk('local')->assertMissing($htmlPath);
    Storage::disk('local')->assertDirectoryEmpty(rtrim($config['archive']['path'], '/'));
});
