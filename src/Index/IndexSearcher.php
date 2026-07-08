<?php

namespace BoardDocsScraper\Index;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Keyword search over the exported index.jsonl. Used by the fluent API, the AI
 * SDK tools, and the console. Matches against agenda text, committee names,
 * dates and attachment titles, and returns ranked results with snippets.
 */
class IndexSearcher
{
    public function __construct(protected array $config)
    {
    }

    /**
     * All index entries.
     *
     * @return Collection<int, array>
     */
    public function entries(): Collection
    {
        $path = $this->config['output']['index'] ?? 'boarddocs/index.jsonl';
        $disk = Storage::disk($this->config['output']['disk'] ?? 'local');

        if (! $disk->exists($path)) {
            return collect();
        }

        return collect(preg_split('/\r?\n/', (string) $disk->get($path)) ?: [])
            ->map(fn ($line) => trim($line))
            ->filter()
            ->map(fn ($line) => json_decode($line, true))
            ->filter(fn ($e) => is_array($e))
            ->values();
    }

    /**
     * Search the index and return ranked matches, each augmented with a "score"
     * and a "snippet" around the first agenda-text hit.
     *
     * @return Collection<int, array>
     */
    public function search(string $query, ?int $limit = null, ?string $committee = null): Collection
    {
        $limit ??= (int) ($this->config['ai']['max_results'] ?? 20);
        $terms = $this->terms($query);

        return $this->entries()
            ->when($committee !== null, fn ($c) => $c->filter(
                fn ($e) => stripos($e['committee'] ?? '', $committee) !== false
            ))
            ->map(function ($entry) use ($terms) {
                $score = $this->score($entry, $terms);

                return $score > 0
                    ? array_merge($entry, [
                        'score' => $score,
                        'snippet' => $this->snippet($entry['agenda_text'] ?? '', $terms),
                    ])
                    : null;
            })
            ->filter()
            ->sortByDesc('score')
            ->take($limit)
            ->values();
    }

    public function get(string $path): ?array
    {
        return $this->entries()->first(fn ($e) => ($e['path'] ?? null) === $path);
    }

    /**
     * @return string[]
     */
    protected function terms(string $query): array
    {
        return collect(preg_split('/\s+/', strtolower(trim($query))) ?: [])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function score(array $entry, array $terms): int
    {
        if (empty($terms)) {
            return 0;
        }

        $agenda = strtolower($entry['agenda_text'] ?? '');
        $committee = strtolower($entry['committee'] ?? '');
        $date = strtolower($entry['date'] ?? '');
        $attachments = strtolower(implode(' ', array_map(
            fn ($a) => $a['title'] ?? '',
            $entry['attachments'] ?? []
        )));

        $score = 0;
        foreach ($terms as $term) {
            $matched = false;
            $score += substr_count($agenda, $term) * 2;
            if (str_contains($agenda, $term)) {
                $matched = true;
            }
            if (str_contains($attachments, $term)) {
                $score += 3;
                $matched = true;
            }
            if (str_contains($committee, $term)) {
                $score += 2;
                $matched = true;
            }
            if (str_contains($date, $term)) {
                $score += 5;
                $matched = true;
            }
            // Require every term to match somewhere (AND semantics).
            if (! $matched) {
                return 0;
            }
        }

        return $score;
    }

    protected function snippet(string $text, array $terms): string
    {
        $length = (int) ($this->config['ai']['snippet_length'] ?? 300);
        if ($text === '' || empty($terms)) {
            return trim(mb_substr($text, 0, $length));
        }

        $lower = strtolower($text);
        $pos = false;
        foreach ($terms as $term) {
            $p = mb_strpos($lower, $term);
            if ($p !== false && ($pos === false || $p < $pos)) {
                $pos = $p;
            }
        }
        if ($pos === false) {
            return trim(mb_substr($text, 0, $length));
        }

        $start = max(0, $pos - (int) ($length / 3));
        $snippet = mb_substr($text, $start, $length);
        $snippet = preg_replace('/\s+/', ' ', $snippet);

        return trim(($start > 0 ? '…' : '').$snippet.'…');
    }
}
