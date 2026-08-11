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
composer require wiserwebsolutions/laravel-boarddocs-scraper
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
$pdf->save();                          // -> "boarddocs-private/pa-phoe/Public/<committee>/<date>-Agenda.pdf"
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
$client->fetchAgendaHtml($meetingId, $committeeId);
$client->fetchItemAttachments($itemId, $committeeId);   // AttachmentData[]
$client->getBytes($fileUrl);                            // raw attachment bytes
$client->downloadToFile($fileUrl, $destinationPath);    // streamed to disk instead
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

`boarddocs:scan` is `boarddocs:prefetch` followed by `boarddocs:build`, run as one command for
the common case where nothing is currently blocking you:

| Command | Talks to BoardDocs? | What it does |
|---------|---------------------|--------------|
| `boarddocs:prefetch` | Yes, always | Fetches committee/meeting lists, agenda HTML, and attachment files into the archive. Never renders a PDF. |
| `boarddocs:build` | **Never** | Renders meeting PDFs + `index.jsonl` strictly from what's already in the archive. Skips (with a clear message) any meeting whose archive is incomplete, instead of falling back to a live request. |
| `boarddocs:scan` | Yes (via prefetch) | Runs prefetch, then build. If prefetch gets blocked partway through, build still runs and produces PDFs for whatever was already archived. |

```bash
# The common case: fetch + build a whole site in one go
php artisan boarddocs:scan --site=pa/phoe

# Scope it (options apply to whichever step(s) they're relevant to)
php artisan boarddocs:scan --site=pa/phoe --committee=CTNNDT5F7A3B --limit=5 --since=2024-01-01 --until=2024-12-31
php artisan boarddocs:scan --dry-run                    # report what build would do; skips prefetch entirely
php artisan boarddocs:scan --no-attachments             # agenda-only PDFs
php artisan boarddocs:scan --engine=browsershot
php artisan boarddocs:scan --fresh                      # bypass the committee/meeting cache during prefetch
php artisan boarddocs:scan --refresh-recent-days=14      # refresh + re-build recent meetings even if a PDF exists
php artisan boarddocs:scan --memory-limit=512M           # raise memory_limit for the build step

# Or run each step yourself
php artisan boarddocs:prefetch --site=pa/phoe            # fetch only, no PDFs
php artisan boarddocs:build --site=pa/phoe               # build only, zero BoardDocs requests

# Search the exported index
php artisan boarddocs:search budget transportation --committee=Finance --limit=10
php artisan boarddocs:search budget --json               # machine-readable output
```

### Starting over

Two commands wipe previously fetched/built data so the next run starts from scratch
instead of skipping what's already there (e.g. after changing the PDF template):

| Command | Deletes | Leaves untouched |
|---------|---------|-------------------|
| `boarddocs:clear-archive` | Archived agenda HTML, attachments, and cached committee/meeting lists | Generated PDFs + index |
| `boarddocs:clear-output` | Generated PDFs and their `index.jsonl` entries | The archive — `boarddocs:build` can regenerate everything from it |

Both default to the configured site and ask for confirmation; pass `--all` to wipe
every site instead of just one, and `--force` to skip the prompt.

```bash
# Wipe one site's archive so the next prefetch re-fetches everything
php artisan boarddocs:clear-archive --site=pa/phoe

# Wipe one site's generated PDFs (e.g. after switching pdf.template) and rebuild
php artisan boarddocs:clear-output --site=pa/phoe --force
php artisan boarddocs:build --site=pa/phoe

# Wipe absolutely everything
php artisan boarddocs:clear-archive --all --force
php artisan boarddocs:clear-output --all --force
```

### Two on-disk trees: what BoardDocs gave us vs. what we produce

Everything is split across two directories (on the `local` disk by default, i.e. under
`storage/app/private`, since that's already the `local` disk's root in Laravel 11+):

| Tree | Config | Default path | Contents |
|------|--------|---------------|----------|
| What BoardDocs gave us, unmodified | `archive.path` | `boarddocs-public` | Raw print-agenda HTML + every downloaded attachment file, as originally fetched |
| What we produce | `output.path` | `boarddocs-private` | The merged/rewritten agenda PDF + `index.jsonl` |

The names describe provenance, not web visibility — both live under Laravel's private
`local` disk by default; point `output.disk`/`archive.disk` at a public disk yourself if
you want the generated PDFs served directly.

### Surviving BoardDocs blocking your server mid-scan

BoardDocs will eventually 403 a server that scans it too aggressively. `boarddocs:prefetch`
and `boarddocs:build` exist specifically to make that survivable, by splitting "talk to
BoardDocs" from "produce the PDF" into two independent steps:

- `boarddocs:prefetch` fetches the committee list, each committee's meeting list, and every
  not-yet-exported meeting's agenda HTML + attachment files into the archive — nothing else.
  It stops immediately on the first 403 it hits, since every request after that is expected
  to fail the same way.
- `boarddocs:build` never makes a BoardDocs request. It builds PDFs straight from whatever
  is already archived. A meeting whose archive is complete builds fine even while BoardDocs
  is actively blocking you; a meeting whose archive is missing something is skipped with a
  clear message (run prefetch again to fill the gap) instead of silently reaching out live.

Run prefetch on its own — e.g. on a schedule, or as your own retry after a 403 — to warm the
archive ahead of time, then build (or `boarddocs:scan`, later, whenever) picks up from there:

```bash
php artisan boarddocs:prefetch --site=pa/phoe
php artisan boarddocs:prefetch --no-attachments   # only fetch agenda HTML

php artisan boarddocs:build --site=pa/phoe        # builds whatever prefetch already archived
```

#### If it's your deployed server's IP getting blocked

Running `boarddocs:prefetch` on the same server that's already blocked doesn't help — it
hits the same wall. What actually helps is running it from a different network, against
the *same* archive the deployed server's `boarddocs:build` reads from. Point `archive.disk`
(and `output.disk`, if you want) at a shared disk instead of `local` — any Flysystem-backed
disk works, e.g. S3:

```dotenv
BOARDDOCS_ARCHIVE_DISK=s3
```

```bash
composer require league/flysystem-aws-s3-v3
```

Configure an `s3` disk in `config/filesystems.php` with the same bucket/credentials on
both the deployed server and whatever machine you'll run `prefetch` from (your laptop, a
different cloud box, anywhere with a clean IP). Then:

```bash
# From the unblocked machine, against the shared disk:
php artisan boarddocs:prefetch --site=pa/phoe

# On the deployed server, whenever you like — makes zero BoardDocs requests:
php artisan boarddocs:build --site=pa/phoe
```

No package code cares which disk `archive.disk` points to — `Archive` only ever calls
`exists()`/`get()`/`put()` on it, so any Laravel filesystem disk works without further
changes.

`index.jsonl` has the same shape as the original project (one meeting per line):

```json
{"path":"pa-phoe/Public/Policy Committee/2026-06-01-Agenda.pdf","district":"pa-phoe",
 "visibility":"Public","committee":"Policy Committee","date":"2026-06-01","page_count":7,
 "agenda_text":"…","attachments":[{"title":"May 18, 2026 Policy Minutes.pdf","page":4}]}
```

Meetings whose PDF already exists are skipped, except those within
`scan.refresh_recent_days` (default 7) which are re-exported.

## Laravel AI SDK integration

The package ships ready-to-use [AI SDK tools](https://laravel.com/docs/13.x/ai-sdk) so an
agent can search and consume the exported meeting PDFs/index. Install the SDK to use them:

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

### Vector store search (optional)

By default `BoardDocsAgent` searches the local `index.jsonl` via `SearchAgendasTool`
(free, deterministic keyword search). You can instead delegate search to a
[vector store](https://laravel.com/docs/13.x/ai-sdk#vector-stores) so the model performs
semantic retrieval over the actual meeting PDFs — useful when relevant content lives in
scanned attachments or phrasing that keyword search misses.

1. Create a store once (OpenAI or Gemini only) and wire up its ID:

   ```bash
   php artisan boarddocs:vector-store:create "BoardDocs Agendas" --write-env
   ```

   This creates the store, then writes `BOARDDOCS_AI_SEARCH_DRIVER=vector` and
   `BOARDDOCS_VECTOR_STORE_ID` into `.env` for you. Omit `--write-env` to just print the
   values and add them yourself; pass `--provider=openai` (or `gemini`) to pin a provider
   other than your app's default. Equivalent by hand:

   ```php
   use Laravel\Ai\Stores;

   $store = Stores::create('BoardDocs Agendas');
   // put $store->id in .env as BOARDDOCS_VECTOR_STORE_ID
   ```

2. Run `php artisan boarddocs:scan` as usual. Whenever the vector driver is active, the
   scan uploads each newly exported (or refreshed) meeting PDF into the store — tagged
   with `path`/`committee`/`date`/`page_count` metadata so results can be mapped back to
   `GetMeetingTool` — and backfills any already-exported PDFs that were never synced.

With the driver set to `vector`, `BoardDocsAgent` registers
`Laravel\Ai\Providers\Tools\FileSearch` against that store instead of `SearchAgendasTool`
(falling back to `SearchAgendasTool` automatically if `vector_store.id` isn't set).
`GetMeetingTool` and `ListCommitteesTool` are unaffected by the driver either way.

## Configuration

See `config/boarddocs.php`. Highlights:

| Key | Purpose |
|-----|---------|
| `site`, `base_url` | Default site slug and host |
| `http.request_delay` | Polite delay between requests (seconds) |
| `http.timeout` | Per-request timeout (seconds) |
| `http.debug` | Log every request/response (status, timing, headers/body) — see [Troubleshooting](#troubleshooting) |
| `cache.store`, `cache.ttl` | Cache store + TTL for committee/meeting/agenda data |
| `output.disk`, `output.path`, `output.index` | Where PDFs and `index.jsonl` are written |
| `archive.enabled`, `archive.disk`, `archive.path` | Where BoardDocs' raw agenda HTML/attachments are kept, unmodified (see [Two on-disk trees](#two-on-disk-trees-what-boarddocs-gave-us-vs-what-we-produce)) |
| `pdf.engine` | `tcpdf` or `browsershot` |
| `pdf.self_contained`, `pdf.remap_links`, `pdf.embed_non_pdf` | Self-contained PDF behavior |
| `pdf.template`, `pdf.templates` | Controls the fonts/colors/spacing of the generated PDF — see [PDF templates](#pdf-templates) |
| `scan.refresh_recent_days` | Re-export window for recent meetings |
| `ai.search_driver` | `jsonl` (default, local keyword search) or `vector` |
| `ai.vector_store.id`, `ai.vector_store.provider` | Vector store used when `ai.search_driver` is `vector` |

## PDF templates

`pdf.template` (default `default`) controls the fonts, colors, and spacing applied to
the agenda in the generated PDF. `BoardDocsScraper\Pdf\Templates\DefaultTemplate` is a
clean, modern, minimal-color design: neutral grays for body text with a single muted-blue
accent for headings and links.

To build a custom template, implement `BoardDocsScraper\Pdf\Templates\PdfTemplate`:

```php
use BoardDocsScraper\Pdf\Templates\PdfTemplate;

class MyTemplate implements PdfTemplate
{
    public function styleBlock(): string
    {
        // A <style> block for TCPDF's writeHTML(). TCPDF's CSS parser only
        // understands a small subset (core PDF fonts, no flexbox/grid,
        // unreliable borders on non-table elements) — keep this simple.
        return '<style>body { font-family: helvetica; font-size: 10pt; }</style>';
    }

    public function document(string $body, string $baseUrl): string
    {
        // A full HTML document for the browsershot engine, which renders with
        // real headless Chrome and supports the full CSS spec.
        return "<!DOCTYPE html><html><head><style>...</style></head><body>{$body}</body></html>";
    }
}
```

Register it under `pdf.templates` in `config/boarddocs.php` and point `pdf.template` at
its key, or set `pdf.template` directly to the class name:

```php
'pdf' => [
    'template' => 'mine',
    'templates' => [
        'mine' => \App\Pdf\MyTemplate::class,
    ],
],
```

## Troubleshooting

BoardDocs will start returning `403` responses if a scan hits it too fast or too long.
Enable request/response logging to see exactly when that starts happening:

```dotenv
BOARDDOCS_HTTP_DEBUG=true
```

Every outbound request and response (method, URL, status, elapsed time, headers, and a
truncated body) is logged, and any `403` is additionally logged as a `warning` tagged
`boarddocs.blocked`. Logs go to a `boarddocs` log channel if your app defines one in
`config/logging.php`, otherwise to the app's default channel. If you're seeing 403s,
raise `http.request_delay` (`BOARDDOCS_REQUEST_DELAY`), scope the scan down with
`--committee`/`--limit`/`--since`, or run `boarddocs:prefetch` during a window when
BoardDocs is reachable so `boarddocs:scan` can build PDFs from the archive afterward —
see [Surviving BoardDocs blocking your server mid-scan](#surviving-boarddocs-blocking-your-server-mid-scan).

## Testing

```bash
composer install
vendor/bin/pest
```

`laravel/ai` is a dev dependency (`composer install` pulls it in) so the `tests/Feature/Ai`
suite — `BoardDocsAgent`, the AI SDK tool classes, `VectorStoreSync`, and
`boarddocs:vector-store:create` — is fully exercised against the SDK's fakes (`Stores::fake()`)
by default. If `laravel/ai` isn't present (e.g. `composer install --no-dev`), that suite
skips itself with a clear message instead of failing.

## License

MIT
