<?php

use BoardDocsScraper\Support\CommitteeCollection;
use Illuminate\Support\Facades\Http;

function fakeCommitteesSite(): void
{
    $committeeHtml = '<a class="committee-trigger" committeeid="AAAAAAAAAA01" aria-label="Board of School Directors"></a>';

    Http::fake([
        '*Board.nsf/Public' => Http::response($committeeHtml, 200),
    ]);
}

it('returns a committee collection that can refresh the cache', function () {
    fakeCommitteesSite();

    $committees = BoardDocs()->site('pa/phoe')->committees();
    expect($committees)->toBeInstanceOf(CommitteeCollection::class);

    // Second read is served from cache — the landing page is fetched once.
    BoardDocs()->site('pa/phoe')->committees();
    Http::assertSentCount(1);

    // refresh() busts the cache and re-fetches, returning a populated collection.
    $refreshed = $committees->refresh();
    expect($refreshed)->toBeInstanceOf(CommitteeCollection::class);
    expect($refreshed->first()->name)->toBe('Board of School Directors');
    Http::assertSentCount(2);
});
