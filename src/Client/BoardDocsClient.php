<?php

namespace BoardDocsScraper\Client;

use BoardDocsScraper\Data\AgendaItemData;
use BoardDocsScraper\Data\AttachmentData;
use BoardDocsScraper\Data\CommitteeData;
use BoardDocsScraper\Data\MeetingData;
use BoardDocsScraper\Exceptions\BoardDocsException;
use BoardDocsScraper\Parsing\AgendaParser;
use BoardDocsScraper\Parsing\CommitteeParser;
use BoardDocsScraper\Parsing\FileLinkParser;
use BoardDocsScraper\Support\Urls;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Low-level HTTP client for the public BoardDocs XHR endpoints. This is the
 * "unofficial API" surface; the fluent Resource layer sits on top of it.
 *
 * Public-only endpoints used (mirrors the Python exporter):
 *   GET  {base}/Public                     -> committee discovery
 *   POST {base}/BD-GetMeetingsList?open    -> meeting list (JSON)
 *   POST {base}/BD-GetAgenda?open          -> agenda item HTML
 *   POST {base}/PRINT-AgendaDetailed?open  -> printable agenda HTML
 *   POST {base}/BD-GetPublicFiles?open     -> attachment links HTML
 *   GET  {file url}                        -> attachment bytes
 */
class BoardDocsClient
{
    protected string $site;

    protected string $baseUrl;

    public function __construct(
        string $site,
        protected array $config,
        protected HttpFactory $http,
        protected CacheRepository $cache,
    ) {
        $this->site = trim($site, '/');
        $this->baseUrl = rtrim($config['base_url'], '/').'/'.$this->site.'/Board.nsf';
    }

    public function site(): string
    {
        return $this->site;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /* ---------------------------------------------------------------------
     | High-level (cached) reads
     * ------------------------------------------------------------------- */

    /**
     * @return CommitteeData[]
     */
    public function discoverCommittees(?string $pageUrl = null, bool $refresh = false): array
    {
        $pageUrl ??= $this->baseUrl.'/Public';

        if ($refresh) {
            $this->forget('committees:'.md5($pageUrl));
        }

        // Cache the plain-array shape (not hydrated objects): cache stores may
        // unserialize with allowed_classes restrictions (e.g. the framework
        // default cache.serializable_classes = false), which would otherwise
        // turn cached objects into __PHP_Incomplete_Class instances.
        $rows = $this->remember('committees:'.md5($pageUrl), function () use ($pageUrl) {
            $this->delay();
            $html = $this->request()->get($pageUrl)->throw()->body();

            return array_map(fn (CommitteeData $c) => $c->toArray(), CommitteeParser::parse($html));
        });

        return array_map(fn (array $row) => CommitteeData::fromArray($row), $rows);
    }

    /**
     * @return MeetingData[] sorted newest first
     */
    public function listMeetings(string $committeeId): array
    {
        // Cache the plain-array shape (not hydrated objects); see the note in
        // discoverCommittees() about allowed_classes-restricted unserialize.
        $rows = $this->remember('meetings:'.$committeeId, function () use ($committeeId) {
            $raw = $this->post('BD-GetMeetingsList', 'current_committee_id='.$committeeId);
            $data = json_decode($raw, true);

            if (! is_array($data)) {
                throw new BoardDocsException("Unexpected meetings payload for committee {$committeeId}.");
            }

            $meetings = [];
            foreach ($data as $row) {
                if (empty($row['numberdate'] ?? null)) {
                    continue;
                }
                $meetings[] = new MeetingData(
                    unique: (string) $row['unique'],
                    name: trim((string) ($row['name'] ?? '')),
                    numberdate: (string) $row['numberdate'],
                    unid: (string) ($row['unid'] ?? ''),
                );
            }

            usort($meetings, fn (MeetingData $a, MeetingData $b) => strcmp($b->numberdate, $a->numberdate));

            return array_map(fn (MeetingData $m) => $m->toArray(), $meetings);
        });

        return array_map(fn (array $row) => MeetingData::fromArray($row), $rows);
    }

    public function fetchPrintAgendaHtml(string $meetingId, string $committeeId): string
    {
        return $this->remember("print:{$committeeId}:{$meetingId}", fn () => $this->post(
            'PRINT-AgendaDetailed',
            "id={$meetingId}&current_committee_id={$committeeId}",
        ));
    }

    public function fetchAgendaHtml(string $meetingId, string $committeeId): string
    {
        return $this->remember("agenda:{$committeeId}:{$meetingId}", fn () => $this->post(
            'BD-GetAgenda',
            "id={$meetingId}&current_committee_id={$committeeId}",
        ));
    }

    /**
     * @return AgendaItemData[]
     */
    public function fetchAgendaItems(string $meetingId, string $committeeId): array
    {
        return AgendaParser::parseItems($this->fetchAgendaHtml($meetingId, $committeeId));
    }

    /**
     * @return AttachmentData[]
     */
    public function fetchItemAttachments(string $itemId, string $committeeId): array
    {
        $postData = "id={$itemId}&current_committee_id={$committeeId}";
        $attachments = [];
        $seen = [];

        // Public export only queries the public files endpoint.
        foreach (['BD-GetPublicFiles'] as $endpoint) {
            $resp = $this->postRaw($endpoint, $postData);
            if ($resp->status() !== 200) {
                continue;
            }
            foreach (FileLinkParser::parse($resp->body()) as $att) {
                if (! isset($seen[$att->unique])) {
                    $seen[$att->unique] = true;
                    $attachments[] = $att;
                }
            }
        }

        return $attachments;
    }

    /* ---------------------------------------------------------------------
     | Low-level HTTP
     * ------------------------------------------------------------------- */

    public function post(string $endpoint, string $data): string
    {
        return $this->postRaw($endpoint, $data)->throw()->body();
    }

    public function postRaw(string $endpoint, string $data): Response
    {
        $url = $this->baseUrl.'/'.$endpoint;
        if (! str_ends_with($endpoint, '?open')) {
            $url .= '?open';
        }

        $this->delay();

        return $this->request()
            ->withBody($data, 'application/x-www-form-urlencoded; charset=UTF-8')
            ->post($url);
    }

    public function getBytes(string $url): string
    {
        $url = Urls::resolveAttachmentUrl($url, $this->baseUrl);
        $this->delay();

        return $this->request()->get($url)->throw()->body();
    }

    /**
     * Stream a download straight to disk instead of buffering it in memory,
     * so large attachments do not add their full size to the PHP heap on top
     * of what TCPDF/FPDI already hold while assembling the merged PDF.
     *
     * @return int  bytes written
     */
    public function downloadToFile(string $url, string $destination): int
    {
        $url = Urls::resolveAttachmentUrl($url, $this->baseUrl);
        $this->delay();

        $this->request()->sink($destination)->get($url)->throw();

        return is_file($destination) ? (int) filesize($destination) : 0;
    }

    protected function request(): PendingRequest
    {
        return $this->http
            ->timeout((int) $this->config['http']['timeout'])
            ->withHeaders([
                'accept' => 'application/json, text/javascript, */*; q=0.01',
                'accept-language' => 'en-US,en;q=0.9',
                'x-requested-with' => 'XMLHttpRequest',
                'user-agent' => $this->config['http']['user_agent'],
            ]);
    }

    protected function delay(): void
    {
        $delay = (float) ($this->config['http']['request_delay'] ?? 0);
        if ($delay > 0) {
            usleep((int) ($delay * 1_000_000));
        }
    }

    /* ---------------------------------------------------------------------
     | Cache
     * ------------------------------------------------------------------- */

    /**
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    protected function remember(string $key, callable $callback): mixed
    {
        if (! ($this->config['cache']['enabled'] ?? true)) {
            return $callback();
        }

        $fullKey = $this->cacheKey($key);
        $ttl = (int) ($this->config['cache']['ttl'] ?? 3600);

        return $this->cache->remember($fullKey, $ttl, $callback);
    }

    public function forget(string $key): void
    {
        $this->cache->forget($this->cacheKey($key));
    }

    protected function cacheKey(string $key): string
    {
        $prefix = $this->config['cache']['prefix'] ?? 'boarddocs';

        return "{$prefix}:{$this->site}:{$key}";
    }
}
