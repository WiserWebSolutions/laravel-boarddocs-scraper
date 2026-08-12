<?php

namespace BoardDocsScraper\Ai;

use BoardDocsScraper\Ai\Tools\GetMeetingTool;
use BoardDocsScraper\Ai\Tools\ListCommitteesTool;
use BoardDocsScraper\Ai\Tools\SearchAgendasTool;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\FileSearch;
use Stringable;

/**
 * A ready-to-use Laravel AI SDK agent wired with the BoardDocs tools, so host
 * apps can drop in agenda Q&A with a single line:
 *
 *   $answer = (new BoardDocsAgent)->prompt('What did the board discuss about the 2024 budget?');
 *
 * Extend or copy it to customise the instructions or provider/model.
 */
class BoardDocsAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        if ($this->usingVectorSearch()) {
            return <<<'TXT'
            You are a research assistant for a school district's BoardDocs meeting archive.
            Use the file search tool to find relevant agenda content.

            Guidelines:
            - Start by searching with concise keywords. If results are thin, broaden terms.
            - Each retrieved chunk's metadata carries "kind" (agenda_pdf, agenda_html, or
              attachment), "committee", "date", and — for attachments — "bookmark"/"title".
              Use these to identify exactly which meeting and, where relevant, which
              attachment a piece of content came from.
            - Cite the meeting date and committee for every claim, and mention the source
              document (agenda or attachment title) so a human can find it.
            - If nothing relevant is found, say so plainly rather than guessing.
            TXT;
        }

        return <<<'TXT'
        You are a research assistant for a school district's BoardDocs meeting archive.
        Use the provided tools to search exported agendas and read individual meetings.

        Guidelines:
        - Start by searching with concise keywords. If results are thin, broaden terms.
        - Every search result carries a "path" — pass it to the get-meeting tool to read
          the full agenda text and see which attachments (and their PDF page numbers)
          are relevant.
        - Cite the meeting date and committee for every claim, and mention the source
          PDF path so a human can open it.
        - If nothing relevant is found, say so plainly rather than guessing.
        TXT;
    }

    /**
     * Registers FileSearch against the configured vector store instead of the
     * local SearchAgendasTool when "boarddocs.ai.search_driver" is "vector"
     * (falling back to SearchAgendasTool if no vector_store.id is configured).
     *
     * GetMeetingTool/ListCommitteesTool are omitted under the vector driver:
     * Gemini rejects a request that combines its built-in FileSearch tool with
     * regular function-calling tools (it needs
     * tool_config.include_server_side_tool_invocations, which laravel/ai's
     * Gemini gateway doesn't currently send), so FileSearch has to be the only
     * tool registered whenever it's in play.
     *
     * @return \Laravel\Ai\Contracts\Tool[]
     */
    public function tools(): iterable
    {
        if ($this->usingVectorSearch()) {
            return [new FileSearch(stores: [$this->vectorStoreId()])];
        }

        return [
            new SearchAgendasTool,
            new GetMeetingTool,
            new ListCommitteesTool,
        ];
    }

    protected function usingVectorSearch(): bool
    {
        $config = app('boarddocs')->config();

        return ($config['ai']['search_driver'] ?? 'jsonl') === 'vector'
            && ! empty($this->vectorStoreId());
    }

    protected function vectorStoreId(): ?string
    {
        return app('boarddocs')->config()['ai']['vector_store']['id'] ?? null;
    }
}
