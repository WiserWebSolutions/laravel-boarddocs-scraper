<?php

use BoardDocsScraper\Support\Urls;

it('resolves relative attachment paths without duplicating the path', function () {
    $base = 'https://go.boarddocs.com/pa/phoe/Board.nsf';

    expect(Urls::resolveAttachmentUrl('/pa/phoe/Board.nsf/files/ID/$file/n.pdf', $base))
        ->toBe('https://go.boarddocs.com/pa/phoe/Board.nsf/files/ID/$file/n.pdf');

    expect(Urls::resolveAttachmentUrl('https://example.com/x.pdf', $base))
        ->toBe('https://example.com/x.pdf');

    expect(Urls::resolveAttachmentUrl('files/ID/$file/n.pdf', $base))
        ->toBe('https://go.boarddocs.com/pa/phoe/Board.nsf/files/ID/$file/n.pdf');
});

it('normalizes link URIs by stripping fragments and trailing slashes', function () {
    $base = 'https://go.boarddocs.com/pa/phoe/Board.nsf';

    expect(Urls::normalizeLinkUri('/pa/phoe/Board.nsf/files/ID/', $base))
        ->toBe('https://go.boarddocs.com/pa/phoe/board.nsf/files/id');

    expect(Urls::normalizeLinkUri('https://go.boarddocs.com/x#frag', $base))
        ->toBe('https://go.boarddocs.com/x');
});

it('detects BoardDocs urls', function () {
    expect(Urls::isBoardDocsUrl('https://go.boarddocs.com/x', 'pa/phoe'))->toBeTrue();
    expect(Urls::isBoardDocsUrl('/pa/phoe/Board.nsf/files/x', 'pa/phoe'))->toBeTrue();
    expect(Urls::isBoardDocsUrl('https://example.com', 'pa/phoe'))->toBeFalse();
});

it('extracts BoardDocs document ids from urls', function () {
    expect(Urls::extractDocumentId('https://go.boarddocs.com/x?id=ABC123&y=1'))->toBe('ABC123');
    expect(Urls::extractDocumentId('/pa/phoe/Board.nsf/files/XYZ789/$file/n.pdf'))->toBe('XYZ789');
    expect(Urls::extractDocumentId('https://example.com/nothing'))->toBeNull();
});

it('builds lookup keys that include the file unique', function () {
    $keys = Urls::lookupKeys(
        'https://go.boarddocs.com/pa/phoe/Board.nsf/files/ABC1234567/$file/n.pdf',
        '/pa/phoe/Board.nsf/files/ABC1234567/$file/n.pdf',
        'ABC1234567',
        'https://go.boarddocs.com/pa/phoe/Board.nsf',
        'pa/phoe',
    );

    expect($keys)->toContain('abc1234567');
    expect($keys)->toContain('ABC1234567');
});

it('sanitizes path components', function () {
    expect(Urls::sanitizePathComponent('Board of Directors: 2024/25'))->toBe('Board of Directors- 2024-25');
    expect(Urls::sanitizePathComponent('   '))->toBe('unknown');
});
