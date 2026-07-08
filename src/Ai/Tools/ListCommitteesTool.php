<?php

namespace BoardDocsScraper\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Laravel AI SDK tool: list the committees available on a BoardDocs site (live,
 * cached). Useful for an agent to discover valid committee names/ids to scope a
 * search.
 */
class ListCommitteesTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'List the committees (boards, sub-committees) on a BoardDocs site, '
            .'returning each committee name and id.';
    }

    public function handle(Request $request): Stringable|string
    {
        $site = $request['site'] ?? null;

        $committees = app('boarddocs')->site($site)
            ->committees()
            ->map(fn ($c) => $c->toArray())
            ->all();

        return json_encode($committees, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'site' => $schema->string()
                ->description('Optional BoardDocs site slug (e.g. "pa/phoe"). Defaults to the configured site.'),
        ];
    }
}
