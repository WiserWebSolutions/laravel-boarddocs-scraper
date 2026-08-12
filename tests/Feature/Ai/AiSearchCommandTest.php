<?php

use BoardDocsScraper\Ai\BoardDocsAgent;
use Illuminate\Support\Facades\Artisan;

beforeEach(fn () => skipUnlessAiSdkInstalled());

it('prints the agent\'s answer for a natural-language question', function () {
    BoardDocsAgent::fake(['The board approved the 2024 budget on January 8th.']);

    $exit = Artisan::call('boarddocs:ai-search', ['query' => ['What', 'happened', 'with', 'the', 'budget?']]);

    expect($exit)->toBe(0);
    expect(Artisan::output())->toContain('The board approved the 2024 budget on January 8th.');

    BoardDocsAgent::assertPrompted(fn ($prompt) => $prompt->prompt === 'What happened with the budget?');
});

it('outputs json when --json is passed', function () {
    BoardDocsAgent::fake(['Fake answer.']);

    $exit = Artisan::call('boarddocs:ai-search', ['query' => ['anything'], '--json' => true]);

    expect($exit)->toBe(0);

    $payload = json_decode(Artisan::output(), true);
    expect($payload['text'])->toBe('Fake answer.');
    expect($payload['tool_calls'])->toBe([]);
});
