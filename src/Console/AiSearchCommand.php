<?php

namespace BoardDocsScraper\Console;

use BoardDocsScraper\Ai\BoardDocsAgent;
use Illuminate\Console\Command;
use Laravel\Ai\AiServiceProvider;

/**
 * Ask BoardDocsAgent a natural-language question from the command line — the
 * AI-agent counterpart to `boarddocs:search`'s plain keyword lookup. Uses
 * whichever search tool BoardDocsAgent currently registers (the local
 * SearchAgendasTool, or FileSearch against the configured vector store when
 * boarddocs.ai.search_driver is "vector") plus GetMeetingTool/ListCommitteesTool,
 * and prints the model's final answer.
 */
class AiSearchCommand extends Command
{
    protected $signature = 'boarddocs:ai-search
        {query* : The natural-language question to ask}
        {--provider= : AI provider to use (defaults to the app\'s ai.default config)}
        {--model= : Model to use (defaults to the provider\'s default text model)}
        {--show-tool-calls : Print each tool call the agent made along the way}
        {--json : Output the raw response as JSON (text + tool calls)}';

    protected $description = 'Ask BoardDocsAgent a natural-language question over the exported agendas.';

    public function handle(): int
    {
        if (! class_exists(AiServiceProvider::class)) {
            $this->error('laravel/ai is not installed. Run: composer require laravel/ai');

            return self::FAILURE;
        }

        $query = implode(' ', (array) $this->argument('query'));

        $response = (new BoardDocsAgent)->prompt(
            $query,
            provider: $this->option('provider'),
            model: $this->option('model'),
        );

        if ($this->option('json')) {
            $this->line(json_encode([
                'text' => $response->text,
                'tool_calls' => $response->toolCalls->map(fn ($call) => [
                    'name' => $call->name,
                    'arguments' => $call->arguments,
                ])->all(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($this->option('show-tool-calls') && $response->toolCalls->isNotEmpty()) {
            $this->line('Tool calls:');
            foreach ($response->toolCalls as $call) {
                $this->line('  '.$call->name.'('.json_encode($call->arguments, JSON_UNESCAPED_SLASHES).')');
            }
            $this->newLine();
        }

        $this->line($response->text);

        return self::SUCCESS;
    }
}
