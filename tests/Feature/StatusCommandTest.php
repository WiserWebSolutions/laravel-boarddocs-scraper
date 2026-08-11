<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * fakeBoardDocsForArchive() is declared globally in ArchiveTest.php and
 * reused here.
 */
it('reports a failure when nothing has been archived yet', function () {
    Storage::fake('local');

    $exit = Artisan::call('boarddocs:status', ['--site' => 'pa/phoe']);

    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('Nothing archived yet');
});

it('summarizes cached vs. compiled meetings without making any BoardDocs request', function () {
    Storage::fake('local');
    fakeBoardDocsForArchive();

    Artisan::call('boarddocs:prefetch', ['--site' => 'pa/phoe']);
    Artisan::call('boarddocs:build', ['--site' => 'pa/phoe', '--limit' => 1]);

    Http::fake(['*' => Http::response('blocked', 403)]);

    $exit = Artisan::call('boarddocs:status', ['--site' => 'pa/phoe']);

    expect($exit)->toBe(0);
    expect(Artisan::output())
        ->toContain('2 cached, 1 compiled, 1 additional able to be compiled')
        ->toContain('2 cached meetings found, 1 meetings compiled, 1 additional meetings able to be compiled.')
        ->toContain('Run boarddocs:build to compile meetings into single files.');
    Http::assertNothingSent();
});
