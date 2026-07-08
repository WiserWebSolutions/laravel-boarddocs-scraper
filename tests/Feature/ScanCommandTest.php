<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Fake just enough of BoardDocs for a --dry-run scan: the committee landing
 * page and a meetings list. Dry runs never fetch agendas or attachments.
 */
function fakeBoardDocsForScan(): void
{
    $committeeHtml = '<a class="committee-trigger" committeeid="AAAAAAAAAA01" aria-label="Board of School Directors"></a>';

    $meetingsJson = json_encode([
        ['unique' => 'MTG001', 'name' => 'Regular Meeting', 'numberdate' => '20240108', 'unid' => 'U1'],
    ]);

    Http::fake([
        '*BD-GetMeetingsList*' => Http::response($meetingsJson, 200),
        '*Board.nsf/Public' => Http::response($committeeHtml, 200),
    ]);
}

beforeEach(function () {
    $this->originalMemoryLimit = ini_get('memory_limit');
});

afterEach(function () {
    ini_set('memory_limit', $this->originalMemoryLimit);
});

it('raises the memory limit for the scan', function () {
    Storage::fake('local');
    fakeBoardDocsForScan();
    ini_set('memory_limit', '128M');

    $this->artisan('boarddocs:scan', [
        '--site' => 'pa/phoe',
        '--memory-limit' => '1024M',
        '--dry-run' => true,
    ])->assertSuccessful();

    expect(ini_get('memory_limit'))->toBe('1024M');
});

it('never lowers an already-higher memory limit', function () {
    Storage::fake('local');
    fakeBoardDocsForScan();
    ini_set('memory_limit', '2048M');

    $this->artisan('boarddocs:scan', [
        '--site' => 'pa/phoe',
        '--memory-limit' => '256M',
        '--dry-run' => true,
    ])->assertSuccessful();

    expect(ini_get('memory_limit'))->toBe('2048M');
});

it('falls back to the configured memory limit when no option is passed', function () {
    Storage::fake('local');
    fakeBoardDocsForScan();
    ini_set('memory_limit', '128M');
    config()->set('boarddocs.scan.memory_limit', '768M');

    $this->artisan('boarddocs:scan', [
        '--site' => 'pa/phoe',
        '--dry-run' => true,
    ])->assertSuccessful();

    expect(ini_get('memory_limit'))->toBe('768M');
});
