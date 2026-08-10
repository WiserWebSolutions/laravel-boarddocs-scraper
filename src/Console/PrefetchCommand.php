<?php

namespace BoardDocsScraper\Console;

use BoardDocsScraper\BoardDocsManager;
use BoardDocsScraper\Support\Archive;
use BoardDocsScraper\Support\OutputPaths;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Pre-downloads (into the archive) every not-yet-exported meeting's agenda
 * HTML and attachment files, without rendering any PDFs — including the
 * committee and meeting lists themselves, so `boarddocs:build` can later
 * enumerate everything to build without making any BoardDocs request of its
 * own. Meant to be run while BoardDocs is still reachable so `boarddocs:build`
 * (or `boarddocs:scan`, which runs both) can produce those meetings' PDFs
 * from disk, even if BoardDocs starts returning 403s before it gets to them.
 *
 * Stops immediately on the first 403 — once BoardDocs starts blocking this
 * server, every subsequent request is expected to fail the same way, so
 * there's nothing to gain from continuing to hit it.
 */
class PrefetchCommand extends Command
{
    protected $signature = 'boarddocs:prefetch
        {--site= : BoardDocs site slug (defaults to config, e.g. pa/phoe)}
        {--committee=* : Restrict to these committee ids}
        {--since= : Only meetings on/after this date (YYYY-MM-DD)}
        {--until= : Only meetings on/before this date (YYYY-MM-DD)}
        {--limit= : Maximum meetings per committee}
        {--no-attachments : Only cache agenda HTML, not attachment files}
        {--refresh-recent-days= : Also refresh the archive for meetings within N days, even if a PDF already exists}
        {--fresh : Bypass the committee/meeting cache}';

    protected $description = 'Pre-download BoardDocs agenda HTML + attachments into the archive, without rendering PDFs.';

    public function handle(BoardDocsManager $manager): int
    {
        $config = $manager->config();

        if (! ($config['archive']['enabled'] ?? true)) {
            $this->error('boarddocs.archive.enabled is false — there is nowhere to prefetch into.');

            return self::FAILURE;
        }

        $siteName = $this->option('site') ?: $config['site'];

        $overrides = [];
        if ($this->option('fresh')) {
            $overrides['cache']['enabled'] = false;
        }

        $site = $manager->site($siteName, $overrides);
        $archive = new Archive($config);
        $disk = Storage::disk($config['output']['disk'] ?? 'local');
        $refreshDays = (int) ($this->option('refresh-recent-days') ?? $config['scan']['refresh_recent_days'] ?? 30);

        $committeeIds = array_map('strtolower', (array) $this->option('committee'));
        $since = $this->option('since');
        $until = $this->option('until');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        // Archive the full, unfiltered committee list every run (not just the
        // --committee subset being processed), so boarddocs:build always sees
        // everything currently on the site regardless of how this particular
        // prefetch was scoped.
        try {
            $allCommittees = $site->committees();
        } catch (RequestException $e) {
            if ($e->response->status() === 403) {
                $this->error('BoardDocs is blocking this server (HTTP 403) while discovering committees — stopping early.');
                $this->summarize(0, 0, 1);

                return self::FAILURE;
            }

            throw $e;
        }
        $archive->putCommittees($siteName, $allCommittees->map(fn ($c) => $c->toArray())->all());

        $committees = $allCommittees;
        if (! empty($committeeIds)) {
            $committees = $committees->filter(
                fn ($c) => in_array(strtolower($c->committeeId), $committeeIds, true)
            )->values();
        }

        if ($committees->isEmpty()) {
            $this->warn("No committees found for site '{$siteName}'.");

            return self::FAILURE;
        }

        $cached = $skipped = $failed = 0;

        foreach ($committees as $committee) {
            $this->info("Committee: {$committee->name} ({$committee->committeeId})");

            // Same reasoning as above: archive this committee's full meeting
            // list unfiltered, then apply --since/--until/--limit only to
            // decide what to actually prefetch material for right now.
            try {
                $allMeetings = $committee->meetings();
            } catch (RequestException $e) {
                if ($e->response->status() === 403) {
                    $this->error("  BoardDocs is blocking this server (HTTP 403) while listing meetings for {$committee->name} — stopping early.");
                    $this->summarize($cached, $skipped, $failed);

                    return self::FAILURE;
                }

                throw $e;
            }
            $archive->putMeetings($siteName, $committee->name, $allMeetings->map(fn ($m) => $m->toArray())->all());

            $meetings = $allMeetings;
            if ($since) {
                $needle = str_replace('-', '', $since);
                $meetings = $meetings->filter(fn ($m) => $m->numberdate() >= $needle);
            }
            if ($until) {
                $needle = str_replace('-', '', $until);
                $meetings = $meetings->filter(fn ($m) => $m->numberdate() <= $needle);
            }
            if ($limit !== null) {
                $meetings = $meetings->take($limit);
            }

            foreach ($meetings as $meeting) {
                $rel = OutputPaths::meetingPath($config, $siteName, $committee->name, $meeting->date());

                if ($disk->exists($rel) && ! $this->withinRecent($meeting->date(), $refreshDays)) {
                    $this->line("  skip already exported {$meeting->date()}");
                    $skipped++;

                    continue;
                }

                try {
                    $agenda = $meeting->agenda();
                    $agenda->html();

                    $count = 0;
                    if (! $this->option('no-attachments')) {
                        $count = count($agenda->cacheAttachments());
                    }

                    $this->info("  cached {$meeting->date()} ({$count} attachments)");
                    $cached++;
                } catch (RequestException $e) {
                    $failed++;

                    if ($e->response->status() === 403) {
                        $this->error("  BoardDocs is blocking this server (HTTP 403) at {$committee->name} {$meeting->date()} — stopping early.");
                        $this->summarize($cached, $skipped, $failed);

                        return self::FAILURE;
                    }

                    $this->error("  failed {$meeting->date()}: ".$e->getMessage());
                } catch (\Throwable $e) {
                    $failed++;
                    $this->error("  failed {$meeting->date()}: ".$e->getMessage());
                }
            }
        }

        $this->summarize($cached, $skipped, $failed);

        return self::SUCCESS;
    }

    protected function summarize(int $cached, int $skipped, int $failed): void
    {
        $this->newLine();
        $this->info("Done. cached={$cached} skipped={$skipped} failed={$failed}");
    }

    protected function withinRecent(string $isoDate, int $days): bool
    {
        if ($days <= 0) {
            return false;
        }

        try {
            return Carbon::parse($isoDate)->gte(Carbon::today()->subDays($days));
        } catch (\Throwable $e) {
            return false;
        }
    }
}
