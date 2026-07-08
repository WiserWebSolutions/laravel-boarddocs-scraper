<?php

namespace BoardDocsScraper\Ai;

use BoardDocsScraper\Ai\Tools\GetMeetingTool;
use BoardDocsScraper\Ai\Tools\ListCommitteesTool;
use BoardDocsScraper\Ai\Tools\SearchAgendasTool;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
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
        return <<<'TXT'
        You are a research assistant for a school district's BoardDocs meeting archive.
        Use the provided tools to search exported agendas and read individual meetings.

        Guidelines:
        - Start by searching with concise keywords. If results are thin, broaden terms.
        - Use the get-meeting tool with a result's "path" to read the full agenda text
          and see which attachments (and their PDF page numbers) are relevant.
        - Cite the meeting date and committee for every claim, and mention the source
          PDF path so a human can open it.
        - If nothing relevant is found, say so plainly rather than guessing.
        TXT;
    }

    /**
     * @return \Laravel\Ai\Contracts\Tool[]
     */
    public function tools(): iterable
    {
        return [
            new SearchAgendasTool,
            new GetMeetingTool,
            new ListCommitteesTool,
        ];
    }
}
