<?php

namespace BoardDocsScraper\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Laravel AI SDK tool: keyword-search the exported BoardDocs agendas so an agent
 * can quickly find the meetings relevant to a question.
 *
 * Register it on an agent:
 *   public function tools(): iterable { return [new SearchAgendasTool]; }
 */
class SearchAgendasTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Search exported BoardDocs meeting agendas by keyword. Returns matching '
            .'meetings with date, committee, a text snippet, attachment titles, and the '
            .'"path" you can pass to the get-meeting tool for full details.';
    }

    public function handle(Request $request): Stringable|string
    {
        $results = app('boarddocs')->searcher()->search(
            (string) $request['query'],
            isset($request['limit']) ? (int) $request['limit'] : null,
            $request['committee'] ?? null,
        );

        if ($results->isEmpty()) {
            return 'No agendas matched: '.$request['query'];
        }

        $payload = $results->map(fn ($r) => [
            'path' => $r['path'] ?? null,
            'date' => $r['date'] ?? null,
            'committee' => $r['committee'] ?? null,
            'page_count' => $r['page_count'] ?? null,
            'snippet' => $r['snippet'] ?? null,
            'attachments' => array_map(fn ($a) => $a['title'] ?? '', $r['attachments'] ?? []),
        ])->all();

        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Keywords to search for across agenda text and attachment titles.')
                ->required(),
            'committee' => $schema->string()
                ->description('Optional: restrict to a committee whose name contains this text.'),
            'limit' => $schema->integer()
                ->description('Optional: maximum number of results (default 20).'),
        ];
    }
}
