<?php

use BoardDocsScraper\Support\OutputPaths;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * fakeBoardDocsForArchive() and samplePdf() are declared globally in
 * ArchiveTest.php / FluentApiTest.php and reused here.
 */
it('reports nothing to build (without failing) when nothing has been archived yet', function () {
    Storage::fake('local');

    $exit = Artisan::call('boarddocs:build', ['--site' => 'pa/phoe', '--dry-run' => true]);

    expect($exit)->toBe(0);
    expect(Artisan::output())->toContain('Nothing archived yet');
});

it('fails a real (non-dry-run) build when nothing has been archived yet', function () {
    Storage::fake('local');

    $exit = Artisan::call('boarddocs:build', ['--site' => 'pa/phoe']);

    expect($exit)->toBe(1);
});

it('builds strictly from the archive and never touches BoardDocs directly', function () {
    Storage::fake('local');
    fakeBoardDocsForArchive();

    Artisan::call('boarddocs:prefetch', ['--site' => 'pa/phoe']);

    // Every possible BoardDocs request now fails; boarddocs:build (run
    // directly, not via boarddocs:scan) must not attempt a single one.
    Http::fake(['*' => Http::response('blocked', 403)]);

    $exit = Artisan::call('boarddocs:build', ['--site' => 'pa/phoe']);

    expect($exit)->toBe(0);
    expect(Artisan::output())->toContain('wrote=2')->toContain('failed=0');
    Http::assertNothingSent();
});

it('skips a meeting with a clear message when its archive is incomplete, without any request', function () {
    Storage::fake('local');
    fakeBoardDocsForArchive();

    Artisan::call('boarddocs:prefetch', ['--site' => 'pa/phoe']);

    $config = app('boarddocs')->config();
    $htmlPath = OutputPaths::archiveAgendaHtmlPath($config, 'pa/phoe', 'Board of School Directors', '2024-01-08');
    Storage::disk('local')->delete($htmlPath);

    Http::fake(['*' => Http::response('blocked', 403)]);

    $exit = Artisan::call('boarddocs:build', ['--site' => 'pa/phoe']);

    // The command as a whole still succeeds; only the affected meeting fails.
    expect($exit)->toBe(0);
    expect(Artisan::output())
        ->toContain('failed 2024-01-08')
        ->toContain('run boarddocs:prefetch first')
        ->toContain('wrote=1');
    Http::assertNothingSent();
});

it('scan runs prefetch then build in one command', function () {
    Storage::fake('local');
    fakeBoardDocsForArchive();

    $exit = Artisan::call('boarddocs:scan', ['--site' => 'pa/phoe']);

    expect($exit)->toBe(0);

    $output = Artisan::output();
    // Evidence of the prefetch step.
    expect($output)->toContain('cached 2024-01-08');
    // Evidence of the build step.
    expect($output)->toContain('wrote=2');

    $config = app('boarddocs')->config();
    Storage::disk('local')->assertExists(
        OutputPaths::meetingPath($config, 'pa/phoe', 'Board of School Directors', '2024-01-08')
    );
});
