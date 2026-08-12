<?php

use BoardDocsScraper\Ai\BoardDocsAgent;
use BoardDocsScraper\Ai\Tools\GetMeetingTool;
use BoardDocsScraper\Ai\Tools\ListCommitteesTool;
use BoardDocsScraper\Ai\Tools\SearchAgendasTool;
use Laravel\Ai\Providers\Tools\FileSearch;

beforeEach(fn () => skipUnlessAiSdkInstalled());

function agentTools(): array
{
    return iterator_to_array((new BoardDocsAgent)->tools());
}

it('registers the local SearchAgendasTool by default', function () {
    $tools = agentTools();

    expect($tools[0])->toBeInstanceOf(SearchAgendasTool::class);
    expect($tools[1])->toBeInstanceOf(GetMeetingTool::class);
    expect($tools[2])->toBeInstanceOf(ListCommitteesTool::class);
});

it('registers only FileSearch against the configured vector store when the driver is "vector"', function () {
    config()->set('boarddocs.ai.search_driver', 'vector');
    config()->set('boarddocs.ai.vector_store.id', 'store_abc123');

    $tools = agentTools();

    // GetMeetingTool/ListCommitteesTool are deliberately omitted here: Gemini
    // rejects a request that mixes its built-in FileSearch tool with regular
    // function-calling tools unless include_server_side_tool_invocations is
    // set, which laravel/ai's Gemini gateway doesn't currently send.
    expect($tools)->toHaveCount(1);
    expect($tools[0])->toBeInstanceOf(FileSearch::class);
    expect($tools[0]->ids())->toBe(['store_abc123']);
});

it('falls back to SearchAgendasTool when the driver is "vector" but no store id is configured', function () {
    config()->set('boarddocs.ai.search_driver', 'vector');
    config()->set('boarddocs.ai.vector_store.id', null);

    $tools = agentTools();

    expect($tools[0])->toBeInstanceOf(SearchAgendasTool::class);
    expect($tools[1])->toBeInstanceOf(GetMeetingTool::class);
    expect($tools[2])->toBeInstanceOf(ListCommitteesTool::class);
});
