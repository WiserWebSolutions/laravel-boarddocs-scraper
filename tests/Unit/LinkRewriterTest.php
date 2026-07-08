<?php

use BoardDocsScraper\Data\SavedAttachment;
use BoardDocsScraper\Pdf\AgendaHtml;
use BoardDocsScraper\Pdf\LinkRewriter;

it('rewrites BoardDocs links to internal PDF page anchors', function () {
    $saved = [
        new SavedAttachment(
            bookmark: 'report.pdf',
            blob: '',
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
