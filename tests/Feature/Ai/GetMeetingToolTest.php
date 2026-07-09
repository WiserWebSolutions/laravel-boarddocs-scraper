<?php

use BoardDocsScraper\Ai\Tools\GetMeetingTool;
use BoardDocsScraper\Index\IndexBuilder;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Tools\Request;

beforeEach(fn () => skipUnlessAiSdkInstalled());

it('returns the full indexed record for a known path', function () {
    Storage::fake('local');

    (new IndexBuilder(config('boarddocs')))->put([
        'path' => 'pa-phoe/Public/Board of School Directors/2024-01-08-Agenda.pdf',
        'committee' => 'Board of School Directors',
        'date' => '2024-01-08',
        'page_count' => 5,
        'agenda_text' => 'Discussion of the 2024 budget.',
        'attachments' => [['title' => 'Budget Report.pdf', 'page' => 2]],
    ])->save();

    $result = (new GetMeetingTool)->handle(new Request([
        'path' => 'pa-phoe/Public/Board of School Directors/2024-01-08-Agenda.pdf',
    ]));

    $entry = json_decode((string) $result, true);
    expect($entry['committee'])->toBe('Board of School Directors');
    expect($entry['agenda_text'])->toBe('Discussion of the 2024 budget.');
    expect($entry['attachments'][0]['title'])->toBe('Budget Report.pdf');
});

it('reports plainly when no meeting matches the path', function () {
    Storage::fake('local');

    $result = (new GetMeetingTool)->handle(new Request(['path' => 'does/not/exist.pdf']));

    expect((string) $result)->toBe('No meeting found for path: does/not/exist.pdf');
});
