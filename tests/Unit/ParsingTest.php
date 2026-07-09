<?php

use BoardDocsScraper\Parsing\AgendaParser;
use BoardDocsScraper\Parsing\CommitteeParser;
use BoardDocsScraper\Parsing\FileLinkParser;

it('parses committees from a landing page', function () {
    $html = <<<'HTML'
    <div>
      <a class="committee-trigger" committeeid="AAAAAAAAAA01" aria-label="Board of School Directors"></a>
      <a class="committee-trigger" committeeid="BBBBBBBBBB02" aria-label="Finance Committee"></a>
    </div>
    HTML;

    $committees = CommitteeParser::parse($html);

    expect($committees)->toHaveCount(2);
    expect($committees[0]->committeeId)->toBe('AAAAAAAAAA01');
    expect($committees[0]->name)->toBe('Board of School Directors');
    expect($committees[1]->name)->toBe('Finance Committee');
});

it('parses public file links with sizes', function () {
    $html = '<a class="public-file" unique="ABC1234567" '
        .'href="/pa/phoe/Board.nsf/files/ABC1234567/$file/report.pdf">Personnel Report (1.2 MB)</a>';

    $files = FileLinkParser::parse($html);

    expect($files)->toHaveCount(1);
    expect($files[0]->unique)->toBe('ABC1234567');
    expect($files[0]->name)->toBe('Personnel Report');
    expect($files[0]->size)->toBe('1.2 MB');
    expect($files[0]->isPdf())->toBeTrue();
});

it('skips administrative/executive/private file anchors entirely', function () {
    // BoardDocs renders these dead anchors even on the public print agenda,
    // but never actually serves the file to the public — downloading one
    // 404s every time. This package is public-data-only, so it must not
    // pick them up via either the class-aware match or the class-blind
    // bare /files/ID/ fallback.
    $html = '<a class="public-file" unique="PUB0000001" href="/pa/phoe/Board.nsf/files/PUB0000001/$file/agenda.pdf">Public Report.pdf (1 MB)</a>'
        .'<a class="admin-file" unique="ADM0000001" href="/pa/phoe/Board.nsf/files/ADM0000001/$file/minutes.docx">Admin Only.docx</a>'
        .'<a class="executive-file" unique="EXE0000001" href="/pa/phoe/Board.nsf/files/EXE0000001/$file/exec.docx">Executive Session.docx</a>'
        .'<a class="private-file" unique="PRV0000001" href="/pa/phoe/Board.nsf/files/PRV0000001/$file/private.docx">Private.docx</a>';

    $files = FileLinkParser::parse($html);

    expect($files)->toHaveCount(1);
    expect($files[0]->unique)->toBe('PUB0000001');
});

it('parses agenda items and their category section', function () {
    $html = <<<'HTML'
    <ul>
      <li class="category" unique="CAT0000001"><span>A</span><span>Public Content</span></li>
      <li class="agenda-item item" id="i1" unique="ITEM0000001" Xtitle="Approve Minutes"><span>1.1</span></li>
      <li class="agenda-item item" id="i2" unique="ITEM0000002" Xtitle="Budget Review"><span>1.2</span> <i class="fa-file-text-o"></i></li>
    </ul>
    HTML;

    $items = AgendaParser::parseItems($html);

    expect($items)->toHaveCount(2);
    expect($items[0]->title)->toBe('Approve Minutes');
    expect($items[0]->order)->toBe('1.1');
    expect($items[0]->contentSection)->toBe('public');
    expect($items[0]->hasAttachment)->toBeFalse();
    expect($items[1]->hasAttachment)->toBeTrue();
});
