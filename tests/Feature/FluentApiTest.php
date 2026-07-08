<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Tcpdf\Fpdi;

/**
 * Build a tiny one-page PDF to stand in for a downloaded attachment.
 */
function samplePdf(string $text = 'Attachment content'): string
{
    $pdf = new Fpdi('P', 'mm', 'LETTER');
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Cell(0, 10, $text, 0, 1);

    return $pdf->Output('a.pdf', 'S');
}

function fakeBoardDocs(): void
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
        '*/files/ABC1234567*' => Http::response(samplePdf(), 200),
        '*Board.nsf/Public' => Http::response($committeeHtml, 200),
    ]);
}

it('walks the fluent chain to committees, meetings, and agenda items', function () {
    fakeBoardDocs();

    $committees = BoardDocs()->site('pa/phoe')->committees();
    expect($committees)->toHaveCount(1);

    $committee = $committees->first();
    expect($committee->name)->toBe('Board of School Directors');

    $meetings = $committee->meetings();
    expect($meetings)->toHaveCount(2);
    // Newest first.
    expect($meetings->first()->date())->toBe('2024-01-08');

    $items = $committee->latest()->agenda()->items();
    expect($items->first()->subject())->toBe('Call to Order');
});

it('renders a self-contained meeting PDF with a merged attachment and remapped link', function () {
    Storage::fake('local');
    fakeBoardDocs();

    $pdf = BoardDocs()->site('pa/phoe')
        ->committees()->first()
        ->agenda()->withAttachments()
        ->toPdf();

    expect(substr($pdf->bytes(), 0, 4))->toBe('%PDF');
    // Agenda page(s) + one merged attachment page.
    expect($pdf->pageCount())->toBeGreaterThan(1);

    $entry = $pdf->indexEntry();
    expect($entry['committee'])->toBe('Board of School Directors');
    expect($entry['date'])->toBe('2024-01-08');
    expect($entry['attachments'])->not->toBeEmpty();
    expect($entry['attachments'][0]['title'])->toBe('Personnel Report.pdf');

    $path = $pdf->save();
    Storage::disk('local')->assertExists($path);
    expect($path)->toContain('pa-phoe/Public/Board of School Directors/2024-01-08-Agenda.pdf');
});

it('caches committee discovery so repeat calls do not refetch', function () {
    fakeBoardDocs();

    BoardDocs()->site('pa/phoe')->committees();
    BoardDocs()->site('pa/phoe')->committees();

    Http::assertSentCount(1); // only the landing page was fetched once
});
