<?php

use BoardDocsScraper\Data\MeetingData;
use BoardDocsScraper\Resources\Committee;
use BoardDocsScraper\Resources\Meeting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Reproduces the framework's hardened cache default (cache.serializable_classes
 * = false), under which any serializing store unserializes with
 * ['allowed_classes' => false]. Caching hydrated data objects would come back
 * as __PHP_Incomplete_Class; caching plain arrays must survive the round-trip.
 */
function useRestrictedSerializingCache(): void
{
    config()->set('cache.serializable_classes', false);
    config()->set('cache.stores.array.serialize', true);
    Cache::forgetDriver('array');
}

function fakeBoardDocsForCache(): void
{
    $committeeHtml = '<a class="committee-trigger" committeeid="AAAAAAAAAA01" aria-label="Board of School Directors"></a>';

    $meetingsJson = json_encode([
        ['unique' => 'MTG001', 'name' => 'Regular Meeting', 'numberdate' => '20240108', 'unid' => 'U1'],
        ['unique' => 'MTG002', 'name' => 'Work Session', 'numberdate' => '20231204', 'unid' => 'U2'],
    ]);

    Http::fake([
        '*BD-GetMeetingsList*' => Http::response($meetingsJson, 200),
        '*Board.nsf/Public' => Http::response($committeeHtml, 200),
    ]);
}

it('rehydrates cached committees under a class-restricted serializing store', function () {
    useRestrictedSerializingCache();
    fakeBoardDocsForCache();

    // Prime the cache, then read again — the second call hits the cache and
    // must rehydrate real data rather than incomplete objects.
    BoardDocs()->site('pa/phoe')->committees();
    $committees = BoardDocs()->site('pa/phoe')->committees();

    expect($committees->first())->toBeInstanceOf(Committee::class);
    expect($committees->first()->name)->toBe('Board of School Directors');
    expect($committees->first()->committeeId)->toBe('AAAAAAAAAA01');
    Http::assertSentCount(1); // committee landing page fetched once; rest served from cache
});

it('rehydrates cached meetings under a class-restricted serializing store', function () {
    useRestrictedSerializingCache();
    fakeBoardDocsForCache();

    $committee = BoardDocs()->site('pa/phoe')->committees()->first();
    $committee->meetings();
    $meetings = $committee->meetings();

    expect($meetings->first())->toBeInstanceOf(Meeting::class);
    expect($meetings->first()->data())->toBeInstanceOf(MeetingData::class);
    expect($meetings->first()->date())->toBe('2024-01-08'); // newest first
    expect($meetings->last()->name())->toBe('Work Session');
});
