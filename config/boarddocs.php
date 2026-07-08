<?php

return [

    /*
    |--------------------------------------------------------------------------
    | BoardDocs Site
    |--------------------------------------------------------------------------
    |
    | The hosted BoardDocs base host and the default "site" slug (the segment
    | after the host, e.g. "pa/phoe" for Phoenixville Area SD). Any call that
    | does not pass an explicit site falls back to the default below.
    |
    */

    'base_url' => env('BOARDDOCS_BASE_URL', 'https://go.boarddocs.com'),

    'site' => env('BOARDDOCS_SITE', 'pa/phoe'),

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    |
    | Tuning for the outbound requests made against BoardDocs XHR endpoints.
    | "request_delay" throttles between requests (seconds) to stay polite, and
    | mirrors REQUEST_DELAY_SEC from the original Python exporter.
    |
    */

    'http' => [
        'timeout' => (int) env('BOARDDOCS_HTTP_TIMEOUT', 120),
        'request_delay' => (float) env('BOARDDOCS_REQUEST_DELAY', 0.25),
        'user_agent' => env(
            'BOARDDOCS_USER_AGENT',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '.
            '(KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36'
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | Committee lists, meeting lists and agenda payloads are cached so repeated
    | fluent calls (and AI tools) stay fast. Set "store" to null to use the
    | application's default cache store.
    |
    */

    'cache' => [
        'enabled' => (bool) env('BOARDDOCS_CACHE_ENABLED', true),
        'store' => env('BOARDDOCS_CACHE_STORE'),
        'ttl' => (int) env('BOARDDOCS_CACHE_TTL', 3600),
        'prefix' => env('BOARDDOCS_CACHE_PREFIX', 'boarddocs'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Output
    |--------------------------------------------------------------------------
    |
    | Where exported meeting PDFs and the JSONL search index are written. "disk"
    | is any Laravel filesystem disk. The on-disk layout mirrors the original
    | project: {path}/{district}/Public/{committee}/{YYYY-MM-DD}-Agenda.pdf
    |
    */

    'output' => [
        'disk' => env('BOARDDOCS_DISK', 'local'),
        'path' => env('BOARDDOCS_OUTPUT_PATH', 'boarddocs'),
        'index' => env('BOARDDOCS_INDEX_PATH', 'boarddocs/index.jsonl'),
        'visibility' => 'Public',
    ],

    /*
    |--------------------------------------------------------------------------
    | PDF generation
    |--------------------------------------------------------------------------
    |
    | engine:          "tcpdf" (default, pure PHP) or "browsershot" (headless
    |                  Chrome, higher fidelity). Only the tcpdf engine can
    |                  rewrite remote BoardDocs links into in-document anchors.
    | self_contained:  merge every PDF attachment inline so the meeting PDF is
    |                  a single portable document (like the original project).
    | remap_links:     convert remote BoardDocs links in the agenda into PDF
    |                  GoTo anchors that jump to the merged attachment pages.
    | embed_non_pdf:   attach non-PDF files (docx, xlsx, ...) as embedded files.
    |
    */

    'pdf' => [
        'engine' => env('BOARDDOCS_PDF_ENGINE', 'tcpdf'),
        'self_contained' => (bool) env('BOARDDOCS_SELF_CONTAINED', true),
        'remap_links' => (bool) env('BOARDDOCS_REMAP_LINKS', true),
        'embed_non_pdf' => (bool) env('BOARDDOCS_EMBED_NON_PDF', true),
        'page_size' => env('BOARDDOCS_PAGE_SIZE', 'LETTER'),
        'orientation' => env('BOARDDOCS_PAGE_ORIENTATION', 'P'),
        'margins' => [
            'top' => 15,
            'right' => 15,
            'bottom' => 15,
            'left' => 15,
        ],
        'browsershot' => [
            'node_binary' => env('BOARDDOCS_NODE_BINARY'),
            'npm_binary' => env('BOARDDOCS_NPM_BINARY'),
            'chrome_path' => env('BOARDDOCS_CHROME_PATH'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Scanning
    |--------------------------------------------------------------------------
    |
    | refresh_recent_days re-exports meetings whose date falls within the last
    | N days even if a PDF already exists (agendas change close to a meeting).
    |
    | memory_limit raises PHP's memory_limit for the duration of a scan.
    | Assembling a self-contained PDF holds every attachment's bytes in memory
    | while TCPDF/FPDI buffer the merged document, which easily exceeds the
    | default 128M on real meetings. Accepts any ini shorthand ("512M", "1G")
    | or "-1" for unlimited; the scan only ever raises, never lowers, the limit.
    |
    */

    'scan' => [
        'refresh_recent_days' => (int) env('BOARDDOCS_REFRESH_RECENT_DAYS', 30),
        'memory_limit' => env('BOARDDOCS_MEMORY_LIMIT', '512M'),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI
    |--------------------------------------------------------------------------
    |
    | Defaults for the bundled Laravel AI SDK tools that search the exported
    | index so agents can quickly find and consume agenda information.
    |
    */

    'ai' => [
        'max_results' => (int) env('BOARDDOCS_AI_MAX_RESULTS', 20),
        'snippet_length' => (int) env('BOARDDOCS_AI_SNIPPET_LENGTH', 300),
    ],

];
