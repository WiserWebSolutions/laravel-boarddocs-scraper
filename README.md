# Laravel BoardDocs Scraper

> **AI-authored notice**
> The initial commit of this project was made by an AI assistant that was asked
> to convert specific components of a private Python script into a
> publishable Laravel package.

A Laravel package that scans **public** [BoardDocs](https://go.boarddocs.com) sites for
meeting agendas and attachments, exposes a fluent, Laravel-flavored "unofficial API,"
and exports **self-contained meeting PDFs** — with remote BoardDocs links rewritten into
in-document anchors — plus a JSONL search index that plugs into the
[Laravel AI SDK](https://laravel.com/docs/13.x/ai-sdk).

It is a PHP/Laravel port of the Python [AbandonBoard](https://github.com/) exporter, so a
district can archive its agendas before losing BoardDocs access. **Public data only** —
the private/login features of the original are intentionally omitted.

```php
BoardDocs()->site('pa/phoe')
    ->committees()->first()
    ->agenda()->withAttachments()
    ->toPdf()
    ->save();
```

## Requirements

- PHP 8.2+
- Laravel 11, 12, or 13
- `tecnickcom/tcpdf` + `setasign/fpdi` (installed automatically) for PDF assembly

## Installation

```bash
composer require graboyes/laravel-boarddocs-scraper
php artisan vendor:publish --tag=boarddocs-config
```

Set your default site in `.env`:

```dotenv
BOARDDOCS_SITE="pa/phoe"
```

## The fluent API

The `BoardDocs()` helper (or the `BoardDocs` facade) is the entry point. Committee and
meeting lists are returned as Laravel Collections and are cached, so `->first()`,
`->firstWhere()`, `->map()` etc. all work as expected.

```php
use BoardDocsScraper\Facades\BoardDocs;

// Discover committees (cached)
$committees = BoardDocs::site('pa/phoe')->committees();      // Collection<Committee>
$board = BoardDocs::site('pa/phoe')->committeeNamed('Directors');

// Meetings for a committee (newest first, cached)
$meetings = $board->meetings();                              // Collection<Meeting>
$latest   = $board->latest();                                // ?Meeting

// Agenda for a meeting
$agenda = $latest->agenda();
$agenda->items();          // Collection<AgendaItemData>
$agenda->attachments();    // Collection<AttachmentData> (metadata; no download)
$agenda->text();           // plain-text agenda summary

// Render a self-contained PDF
$pdf = $agenda->withAttachments()->toPdf();
$pdf->pageCount();                     // int
$pdf->attachments();                   // SavedAttachment[]
$pdf->save();                          // -> "boarddocs/pa-phoe/Public/<committee>/<date>-Agenda.pdf"
$pdf->save('custom/path.pdf', 'r2');   // any Laravel disk
return $pdf->response();               // inline PDF HTTP response
```

The one-liner from the top works too:

```php
BoardDocs()->site('pa/phoe')->committees()->first()->agenda()->withAttachments()->toPdf();
```

The low-level HTTP client (the raw "unofficial API") is available if you need it:

```php
$client = BoardDocs::client('pa/phoe');
$client->discoverCommittees();
$client->listMeetings($committeeId);
$client->fetchPrintAgendaHtml($meetingId, $committeeId);
```

## Self-contained meeting PDFs

With `self_contained` enabled (default), each meeting PDF contains the printed agenda
followed by every PDF attachment merged inline, one **bookmark per attachment**, and
non-PDF attachments embedded as file attachments. When `remap_links` is on, any remote
BoardDocs link in the agenda is rewritten into an **internal PDF anchor** that jumps to
the merged attachment's page — so the document stays fully navigable offline, even if a
district's BoardDocs subscription ends.

Two rendering engines are available (configure `boarddocs.pdf.engine`):

| Engine | Fidelity | Link remap | Dependencies |
|--------|----------|------------|--------------|
| `tcpdf` (default) | Good (agenda pages are simple) | ✅ Yes | pure PHP |
| `browsershot` | High (real Chrome) | ❌ No (attachments still merged) | `spatie/browsershot` + Node + Chromium |

## Scanning & the search index

```bash
# Export a whole site (all committees), updating output/index.jsonl
php artisan boarddocs:scan --site=pa/phoe

# Scope it
php artisan boarddocs:scan --site=pa/phoe --committee=CTNNDT5F7A3B --limit=5 --since=2024-01-01
php artisan boarddocs:scan --dry-run          # list without downloading
php artisan boarddocs:scan --no-attachments   # agenda-only PDFs
php artisan boarddocs:scan --engine=browsershot

# Search the exported index
php artisan boarddocs:search budget transportation --committee=Finance --limit=10
```

`index.jsonl` has the same shape as the original project (one meeting per line):

```json
{"path":"pa-phoe/Public/Policy Committee/2026-06-01-Agenda.pdf","district":"pa-phoe",
 "visibility":"Public","committee":"Policy Committee","date":"2026-06-01","page_count":7,
 "agenda_text":"…","attachments":[{"title":"May 18, 2026 Policy Minutes.pdf","page":4}]}
```

Meetings whose PDF already exists are skipped, except those within
`scan.refresh_recent_days` (default 30) which are re-exported.

## Laravel AI SDK integration

The package ships ready-to-use [AI SDK tools](https://laravel.com/docs/13.x/ai-sdk) so an
agent can search and consume the archive. Install the SDK to use them:

```bash
composer require laravel/ai
```

Use the bundled agent directly:

```php
use BoardDocsScraper\Ai\BoardDocsAgent;

$answer = (new BoardDocsAgent)->prompt('What did the Policy Committee decide about edtech?');
```

…or register the individual tools on your own agent:

```php
use BoardDocsScraper\Ai\Tools\SearchAgendasTool;
use BoardDocsScraper\Ai\Tools\GetMeetingTool;
use BoardDocsScraper\Ai\Tools\ListCommitteesTool;

class MyAgent implements Agent, HasTools
{
    use Promptable;

    public function tools(): iterable
    {
        return [new SearchAgendasTool, new GetMeetingTool, new ListCommitteesTool];
    }
}
```

- **SearchAgendasTool** — keyword search over agenda text + attachment titles; returns
  meetings with snippets and the `path` to fetch full details.
- **GetMeetingTool** — full indexed record (agenda text, attachments + pages) for one `path`.
- **ListCommitteesTool** — live committee list for a site.

## Configuration

See `config/boarddocs.php`. Highlights:

| Key | Purpose |
|-----|---------|
| `site`, `base_url` | Default site slug and host |
| `http.request_delay` | Polite delay between requests (seconds) |
| `cache.store`, `cache.ttl` | Cache store + TTL for committee/meeting/agenda data |
| `output.disk`, `output.path`, `output.index` | Where PDFs and `index.jsonl` are written |
| `pdf.engine` | `tcpdf` or `browsershot` |
| `pdf.self_contained`, `pdf.remap_links`, `pdf.embed_non_pdf` | Self-contained PDF behavior |
| `scan.refresh_recent_days` | Re-export window for recent meetings |

## Testing

```bash
composer install
vendor/bin/pest
```

## License

MIT
