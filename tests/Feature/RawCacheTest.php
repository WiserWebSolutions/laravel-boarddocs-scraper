<?php

use BoardDocsScraper\Support\OutputPaths;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * samplePdf() is declared globally in FluentApiTest.php and reused here.
 *
 * Uses its own BoardDocs fake (rather than FluentApiTest's fakeBoardDocs())
 * because these tests download the same attachment URL across two meetings
 * in a single run, and Http::fake() resolves a bare Http::response(...)
 * value's stream once and reuses it for every matching request — fine when a
 * URL is only ever hit once per test, but it returns an empty body the
 * second time. Wrapping the attachment stub in a closure makes every
 * matching request get a fresh body instead.
 */
function fakeBoardDocsForRawCache(): void
{
    $committeeHtml = '<a class="committee-trigger" committeeid="AAAAAAAAAA01" aria-label="Board of School Directors"></a>';

    $meetingsJson = json_encode([
        ['unique' => 'MTG001', 'name' => 'Regular Meeting', 'numberdate' => '20240108', 'unid' => 'U1'],
        ['unique' => 'MTG002', 'name' => 'Work Session', 'numberdate' => '20231204', 'unid' => 'U2'],
    ]);

    $printHtml = <<<'HTML'
    <html><body>
      <div class="print-meeting-name">Regular Meeting</div>

      <div role="heading" aria-level="1">A. OPENING OF MEETING</div>
      <div tabindex="0" class="container item agendaorder">
        <div><dl class="row"><dt class="col leftcol">Subject</dt><dd class="col rightcol">1. Call to Order</dd></dl></div>
        <dl class="row"><dt class="col leftcol">Category</dt><dd class="col rightcol">A. OPENING OF MEETING</dd></dl>
        <dl class="row"><dt class="col leftcol">Type</dt><dd class="col rightcol">Procedural</dd></dl>
        <div class="itembody"><p>The meeting was called to order at 7:00 PM.</p></div>
      </div>

      <div role="heading" aria-level="1">B. CONSENT AGENDA</div>
      <div tabindex="0" class="container item agendaorder">
        <div><dl class="row"><dt class="col leftcol">Subject</dt><dd class="col rightcol">1. Approve the Personnel Report</dd></dl></div>
        <dl class="row"><dt class="col leftcol">Category</dt><dd class="col rightcol">B. CONSENT AGENDA</dd></dl>
        <dl class="row"><dt class="col leftcol">Type</dt><dd class="col rightcol">Action</dd></dl>
        <div class="itembody"><p>Recommend approval of the personnel report as presented.</p></div>
        <div class="print-files"><a class="public-file print-file" unique="ABC1234567"
           href="/pa/phoe/Board.nsf/files/ABC1234567/$file/report.pdf">Personnel Report.pdf (1.2 MB)</a></div>
      </div>
    </body></html>
    HTML;

    $agendaHtml = '<ul><li class="item" id="i1" unique="ITEM0000001" Xtitle="Approve Minutes"><span>1.1</span></li></ul>';

    Http::fake([
        '*BD-GetMeetingsList*' => Http::response($meetingsJson, 200),
        '*PRINT-AgendaDetailed*' => Http::response($printHtml, 200),
        '*BD-GetAgenda*' => Http::response($agendaHtml, 200),
        '*BD-GetPublicFiles*' => Http::response('', 200),
        '*/files/ABC1234567*' => fn () => Http::response(samplePdf(), 200),
        '*Board.nsf/Public' => Http::response($committeeHtml, 200),
    ]);
}

it('prefetches agenda html and attachment bytes into the raw cache without rendering a pdf', function () {
    Storage::fake('local');
    fakeBoardDocsForRawCache();

    Artisan::call('boarddocs:prefetch', ['--site' => 'pa/phoe']);

    $config = app('boarddocs')->config();
    $htmlPath = OutputPaths::rawAgendaHtmlPath($config, 'pa/phoe', 'Board of School Directors', '2024-01-08');
    $attachmentPath = OutputPaths::rawAttachmentsPath($config, 'pa/phoe', 'Board of School Directors', '2024-01-08')
        .'/Personnel Report.pdf';
    $manifestPath = OutputPaths::rawAttachmentsPath($config, 'pa/phoe', 'Board of School Directors', '2024-01-08')
        .'/manifest.json';

    Storage::disk('local')->assertExists($htmlPath);
    Storage::disk('local')->assertExists($attachmentPath);
    Storage::disk('local')->assertExists($manifestPath);

    // No PDF was written for the prefetched meeting.
    $pdfPath = OutputPaths::meetingPath($config, 'pa/phoe', 'Board of School Directors', '2024-01-08');
    Storage::disk('local')->assertMissing($pdfPath);

    $manifest = json_decode(Storage::disk('local')->get($manifestPath), true);
    expect($manifest)->toHaveCount(1);
    expect($manifest[0]['bookmark'])->toBe('Personnel Report.pdf');
});

it('builds the pdf entirely from the raw cache once BoardDocs starts blocking requests', function () {
    Storage::fake('local');
    fakeBoardDocsForRawCache();

    Artisan::call('boarddocs:prefetch', ['--site' => 'pa/phoe']);

    // BoardDocs now blocks everything; the scan must not need to reach it.
    Http::fake(['*' => Http::response('blocked', 403)]);

    $exit = Artisan::call('boarddocs:scan', ['--site' => 'pa/phoe']);

    expect($exit)->toBe(0);
    expect(Artisan::output())->toContain('wrote=2')
        ->toContain('failed=0');

    $config = app('boarddocs')->config();
    $pdfPath = OutputPaths::meetingPath($config, 'pa/phoe', 'Board of School Directors', '2024-01-08');
    Storage::disk('local')->assertExists($pdfPath);

    Http::assertNothingSent();
});

it('reconnects to BoardDocs only for a specific attachment missing from the cache', function () {
    Storage::fake('local');
    fakeBoardDocsForRawCache();

    Artisan::call('boarddocs:prefetch', ['--site' => 'pa/phoe']);

    $config = app('boarddocs')->config();
    $attachmentPath = OutputPaths::rawAttachmentsPath($config, 'pa/phoe', 'Board of School Directors', '2024-01-08')
        .'/Personnel Report.pdf';

    // Simulate an incomplete cache: the manifest exists, but this one file's
    // bytes were never persisted (or got lost).
    Storage::disk('local')->delete($attachmentPath);
    Storage::disk('local')->assertMissing($attachmentPath);

    Http::fake([
        '*/files/ABC1234567*' => fn () => Http::response(samplePdf(), 200),
        '*' => Http::response('blocked', 403),
    ]);

    $exit = Artisan::call('boarddocs:scan', ['--site' => 'pa/phoe']);

    expect($exit)->toBe(0);
    expect(Artisan::output())->toContain('wrote=2');

    // Only the one missing attachment was re-fetched from BoardDocs (the
    // other meeting's cache was fully intact).
    Http::assertSentCount(1);

    // The cache is backfilled for next time.
    Storage::disk('local')->assertExists($attachmentPath);
});
