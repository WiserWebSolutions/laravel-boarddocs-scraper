<?php

use BoardDocsScraper\Ai\Tools\ListCommitteesTool;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

beforeEach(fn () => skipUnlessAiSdkInstalled());

function fakeCommitteesForAiTool(): void
{
    $committeeHtml = '<a class="committee-trigger" committeeid="AAAAAAAAAA01" aria-label="Board of School Directors"></a>';

    Http::fake([
        '*Board.nsf/Public' => Http::response($committeeHtml, 200),
    ]);
}

it('lists committees for the default configured site', function () {
    fakeCommitteesForAiTool();

    $result = (new ListCommitteesTool)->handle(new Request([]));

    $committees = json_decode((string) $result, true);
    expect($committees)->toHaveCount(1);
    expect($committees[0])->toBe(['committee_id' => 'AAAAAAAAAA01', 'name' => 'Board of School Directors']);
});

it('accepts an explicit site override', function () {
    fakeCommitteesForAiTool();

    $result = (new ListCommitteesTool)->handle(new Request(['site' => 'pa/phoe']));

    expect(json_decode((string) $result, true))->toHaveCount(1);
});
