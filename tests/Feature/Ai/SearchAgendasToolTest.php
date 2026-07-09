<?php

use BoardDocsScraper\Ai\Tools\SearchAgendasTool;
use BoardDocsScraper\Index\IndexBuilder;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Tools\Request;

beforeEach(fn () => skipUnlessAiSdkInstalled());

function seedAiToolsIndex(): void
{
    (new IndexBuilder(config('boarddocs')))
        ->put([
            'path' => 'pa-phoe/Public/Board of School Directors/2024-01-08-Agenda.pdf',
            'district' => 'pa-phoe',
            'visibility' => 'Public',
            'committee' => 'Board of School Directors',
            'date' => '2024-01-08',
            'page_count' => 5,
            'agenda_text' => 'Discussion of the 2024 budget and cafeteria HVAC upgrades.',
            'attachments' => [['title' => 'Budget Report.pdf', 'page' => 2]],
        ])
        ->put([
            'path' => 'pa-phoe/Public/Finance Committee/2024-02-01-Agenda.pdf',
            'district' => 'pa-phoe',
            'visibility' => 'Public',
            'committee' => 'Finance Committee',
            'date' => '2024-02-01',
            'page_count' => 3,
            'agenda_text' => 'Review of transportation contracts.',
            'attachments' => [],
        ])
        ->save();
}

it('returns matching meetings as json with path/date/committee/snippet/attachments', function () {
    Storage::fake('local');
    seedAiToolsIndex();

    $result = (new SearchAgendasTool)->handle(new Request(['query' => 'budget']));
    $payload = json_decode((string) $result, true);

    expect($payload)->toHaveCount(1);
    expect($payload[0])->toMatchArray([
        'path' => 'pa-phoe/Public/Board of School Directors/2024-01-08-Agenda.pdf',
        'date' => '2024-01-08',
        'committee' => 'Board of School Directors',
        'page_count' => 5,
    ]);
    expect($payload[0]['snippet'])->toContain('budget');
    expect($payload[0]['attachments'])->toBe(['Budget Report.pdf']);
});

it('respects the committee filter and limit options', function () {
    Storage::fake('local');
    seedAiToolsIndex();

    $tool = new SearchAgendasTool;

    $wrongCommittee = $tool->handle(new Request(['query' => 'budget', 'committee' => 'Finance']));
    expect((string) $wrongCommittee)->toContain('No agendas matched');

    $rightCommittee = $tool->handle(new Request(['query' => 'budget', 'committee' => 'Board', 'limit' => 1]));
    expect(json_decode((string) $rightCommittee, true))->toHaveCount(1);
});

it('reports no matches plainly instead of an empty payload', function () {
    Storage::fake('local');
    seedAiToolsIndex();

    $result = (new SearchAgendasTool)->handle(new Request(['query' => 'zzz-nonexistent']));

    expect((string) $result)->toBe('No agendas matched: zzz-nonexistent');
});
