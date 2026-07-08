<?php

namespace BoardDocsScraper\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Laravel AI SDK tool: fetch the full indexed record for one meeting (agenda
 * text, page count, and attachment titles + pages) by its index "path".
 */
class GetMeetingTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Get the full details of one exported BoardDocs meeting by its "path" '
            .'(as returned by the search tool): the complete agenda text, page count, '
            .'and the list of attachments with the PDF page each one starts on.';
    }

    public function handle(Request $request): Stringable|string
    {
        $entry = app('boarddocs')->searcher()->get((string) $request['path']);

        if ($entry === null) {
            return 'No meeting found for path: '.$request['path'];
        }

        return json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()
                ->description('The meeting index path, e.g. "pa-phoe/Public/Board of School Directors/2024-01-08-Agenda.pdf".')
                ->required(),
        ];
    }
}
