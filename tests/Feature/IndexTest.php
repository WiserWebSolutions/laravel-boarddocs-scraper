<?php

use BoardDocsScraper\Index\IndexBuilder;
use BoardDocsScraper\Index\IndexSearcher;
use Illuminate\Support\Facades\Storage;

function sampleEntry(array $overrides = []): array
{
    return array_merge([
        'path' => 'pa-phoe/Public/Board of School Directors/2024-01-08-Agenda.pdf',
        'district' => 'pa-phoe',
        'visibility' => 'Public',
        'committee' => 'Board of School Directors',
        'date' => '2024-01-08',
        'page_count' => 5,
        'agenda_text' => 'Discussion of the 2024 budget and cafeteria HVAC upgrades.',
        'attachments' => [['title' => 'Budget Report.pdf', 'page' => 2]],
    ], $overrides);
}

it('writes the index as JSONL and reloads it', function () {
    Storage::fake('local');
    $config = config('boarddocs');

    (new IndexBuilder($config))
        ->put(sampleEntry())
        ->put(sampleEntry([
            'path' => 'pa-phoe/Public/Finance Committee/2024-02-01-Agenda.pdf',
            'committee' => 'Finance Committee',
            'date' => '2024-02-01',
            'agenda_text' => 'Review of transportation contracts.',
            'attachments' => [['title' => 'Transportation Contract.pdf', 'page' => 2]],
        ]))
        ->save();

    Storage::disk('local')->assertExists($config['output']['index']);

    $reloaded = (new IndexBuilder($config))->load();
    expect($reloaded->count())->toBe(2);
});

it('searches the index by keyword with AND semantics and snippets', function () {
    Storage::fake('local');
    $config = config('boarddocs');

    (new IndexBuilder($config))
        ->put(sampleEntry())
        ->put(sampleEntry([
            'path' => 'pa-phoe/Public/Finance Committee/2024-02-01-Agenda.pdf',
            'committee' => 'Finance Committee',
            'date' => '2024-02-01',
            'agenda_text' => 'Review of transportation contracts.',
            'attachments' => [['title' => 'Transportation Contract.pdf', 'page' => 2]],
        ]))
        ->save();

    $searcher = new IndexSearcher($config);

    $budget = $searcher->search('budget');
    expect($budget)->toHaveCount(1);
    expect($budget->first()['committee'])->toBe('Board of School Directors');
    expect($budget->first()['snippet'])->toContain('budget');

    // Attachment titles are searchable.
    expect($searcher->search('Budget Report'))->toHaveCount(1);

    // AND semantics: both terms must appear somewhere.
    expect($searcher->search('budget transportation'))->toHaveCount(0);

    // Committee filter.
    expect($searcher->search('review', committee: 'Finance'))->toHaveCount(1);

    // No matches.
    expect($searcher->search('nonexistentterm'))->toHaveCount(0);
});
