<?php

use BoardDocsScraper\Data\SavedAttachment;
use BoardDocsScraper\Pdf\AgendaHtml;
use BoardDocsScraper\Pdf\LinkRewriter;

it('rewrites BoardDocs links to internal PDF page anchors', function () {
    $saved = [
        new SavedAttachment(
            bookmark: 'report.pdf',
            path: '',
            resolvedUrl: 'https://go.boarddocs.com/pa/phoe/Board.nsf/files/ABC1234567/$file/report.pdf',
            href: '/pa/phoe/Board.nsf/files/ABC1234567/$file/report.pdf',
            fileUnique: 'ABC1234567',
        ),
    ];

    $html = '<p><a href="https://go.boarddocs.com/pa/phoe/Board.nsf/files/ABC1234567/$file/report.pdf">Report</a></p>';

    [$rewritten, $referenced] = LinkRewriter::rewrite(
        $html,
        $saved,
        'https://go.boarddocs.com/pa/phoe/Board.nsf',
        'pa/phoe',
        [0 => 3], // attachment starts on page 3
    );

    expect($rewritten)->toContain('href="#3"');
    expect($referenced)->toBe([0 => true]);
});

it('leaves non-BoardDocs links untouched', function () {
    $html = '<a href="https://example.com/x">External</a>';

    [$rewritten] = LinkRewriter::rewrite($html, [], 'https://go.boarddocs.com/pa/phoe/Board.nsf', 'pa/phoe', []);

    expect($rewritten)->toContain('https://example.com/x');
});

it('cleans scripts and styles from agenda html', function () {
    $html = '<body><script>alert(1)</script><style>.x{}</style><p>Hello</p></body>';

    expect(AgendaHtml::clean($html))
        ->not->toContain('alert')
        ->toContain('<p>Hello</p>');
});

it('strips the "<width> none" border shorthand that crashes TCPDF', function () {
    // TCPDF's getCSSBorderStyle() misreads this common 2-token shorthand as
    // [style, color] instead of [width, style], then throws trying to
    // resolve "none" as a color (TCPDF_COLORS::getSpotColor() case-mismatch
    // bug). A "none" border already paints nothing, so dropping it is safe.
    $html = '<body><img style="box-sizing: border-box; border: 0px none; width: 227px;"></body>';

    $cleaned = AgendaHtml::clean($html);

    expect($cleaned)->not->toContain('none');
    expect($cleaned)->toContain('box-sizing: border-box;');
    expect($cleaned)->toContain('width: 227px;');
});
