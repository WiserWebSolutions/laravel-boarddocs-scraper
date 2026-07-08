<?php

use BoardDocsScraper\Resources\AgendaCategory;
use Illuminate\Support\Facades\Http;

function fakeAgendaSite(): void
{
    $committeeHtml = '<a class="committee-trigger" committeeid="AAAAAAAAAA01" aria-label="Board of School Directors"></a>';

    $meetingsJson = json_encode([
        ['unique' => 'MTG001', 'name' => 'Regular Meeting', 'numberdate' => '20240108', 'unid' => 'U1'],
    ]);

    $printHtml = <<<'HTML'
    <html><body>
      <div role="heading" aria-level="1">A. OPENING OF MEETING</div>
      <div tabindex="0" class="container item agendaorder">
        <div><dl class="row"><dt class="col leftcol">Subject</dt><dd class="col rightcol">1. Call to Order</dd></dl></div>
        <dl class="row"><dt class="col leftcol">Category</dt><dd class="col rightcol">A. OPENING OF MEETING</dd></dl>
        <dl class="row"><dt class="col leftcol">Type</dt><dd class="col rightcol">Procedural</dd></dl>
        <div class="itembody"><p>The meeting was called to order at 7:00 PM.</p></div>
      </div>
      <div tabindex="0" class="container item agendaorder">
        <div><dl class="row"><dt class="col leftcol">Subject</dt><dd class="col rightcol">2. Roll Call</dd></dl></div>
        <dl class="row"><dt class="col leftcol">Category</dt><dd class="col rightcol">A. OPENING OF MEETING</dd></dl>
        <dl class="row"><dt class="col leftcol">Type</dt><dd class="col rightcol">Procedural</dd></dl>
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

    Http::fake([
        '*BD-GetMeetingsList*' => Http::response($meetingsJson, 200),
        '*PRINT-AgendaDetailed*' => Http::response($printHtml, 200),
        '*Board.nsf/Public' => Http::response($committeeHtml, 200),
    ]);
}

it('breaks the agenda into ordered categories, each with its items', function () {
    fakeAgendaSite();

    $categories = BoardDocs()->site('pa/phoe')->committees()->first()->agenda()->categories();

    expect($categories)->toHaveCount(2);
    expect($categories->first())->toBeInstanceOf(AgendaCategory::class);

    $opening = $categories->first();
    expect($opening->order())->toBe('A.');
    expect($opening->name())->toBe('OPENING OF MEETING');
    expect($opening->items())->toHaveCount(2);
    expect($opening->items()->first()->subject())->toBe('Call to Order');
    expect($opening->items()->first()->type)->toBe('Procedural');

    $consent = $categories->last();
    expect($consent->order())->toBe('B.');
    expect($consent->name())->toBe('CONSENT AGENDA');
    expect($consent->items())->toHaveCount(1);
});

it('exposes each item subject and content shown on the agenda page', function () {
    fakeAgendaSite();

    $consentItem = BoardDocs()->site('pa/phoe')->committees()->first()
        ->agenda()->categories()->last()->items()->first();

    expect($consentItem->subject())->toBe('Approve the Personnel Report');
    expect($consentItem->content)->toContain('Recommend approval of the personnel report');
    expect($consentItem->contentText())->toBe('Recommend approval of the personnel report as presented.');
    expect($consentItem->hasAttachment)->toBeTrue();
});

it('lists all agenda items flat, skipping the category rows', function () {
    fakeAgendaSite();

    $items = BoardDocs()->site('pa/phoe')->committees()->first()->agenda()->items();

    expect($items)->toHaveCount(3); // 2 opening + 1 consent, categories not counted
    expect($items->pluck('title')->all())->toBe([
        'Call to Order',
        'Roll Call',
        'Approve the Personnel Report',
    ]);
});
