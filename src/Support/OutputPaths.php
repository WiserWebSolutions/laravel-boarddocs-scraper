<?php

namespace BoardDocsScraper\Support;

/**
 * Builds the on-disk paths for a meeting, split across two trees:
 *
 *   - output.path (default "boarddocs-private"): what we produce — the
 *     merged/rewritten agenda PDF and index.jsonl.
 *   - raw_cache.path (default "boarddocs-public"): what BoardDocs gave us,
 *     unmodified — the raw print-agenda HTML and every downloaded attachment
 *     file, kept whether or not a PDF has been built from them yet. This is
 *     also what Support\RawCache reads from/writes to, so a run that starts
 *     getting 403s can finish building PDFs from what's already on disk.
 *
 * Both mirror the same layout: {base}/{district}/{visibility}/{committee}/{YYYY-MM-DD}-*
 */
class OutputPaths
{
    public static function meetingPath(array $config, string $site, string $committeeName, string $date): string
    {
        return self::build(
            (string) ($config['output']['path'] ?? 'boarddocs-private'),
            $config,
            $site,
            $committeeName,
            $date.'-Agenda.pdf',
        );
    }

    /**
     * The directory a meeting's raw attachment files are archived under, as
     * originally downloaded (unmodified): {raw_cache.path}/{district}/{visibility}/{committee}/{YYYY-MM-DD}-Attachments
     */
    public static function attachmentsPath(array $config, string $site, string $committeeName, string $date): string
    {
        return self::rawAttachmentsPath($config, $site, $committeeName, $date);
    }

    /**
     * Where a meeting's raw print-agenda HTML is cached, as originally
     * downloaded (unmodified): {raw_cache.path}/{district}/{visibility}/{committee}/{YYYY-MM-DD}-Agenda.html
     */
    public static function rawAgendaHtmlPath(array $config, string $site, string $committeeName, string $date): string
    {
        return self::build(
            (string) ($config['raw_cache']['path'] ?? 'boarddocs-public'),
            $config,
            $site,
            $committeeName,
            $date.'-Agenda.html',
        );
    }

    /**
     * Where a meeting's raw attachment files (and their manifest) are kept,
     * as originally downloaded (unmodified): {raw_cache.path}/{district}/{visibility}/{committee}/{YYYY-MM-DD}-Attachments
     */
    public static function rawAttachmentsPath(array $config, string $site, string $committeeName, string $date): string
    {
        return self::build(
            (string) ($config['raw_cache']['path'] ?? 'boarddocs-public'),
            $config,
            $site,
            $committeeName,
            $date.'-Attachments',
        );
    }

    /**
     * Strip the configured output base from a full storage path so it matches the
     * "path" field used in index.jsonl. Paths rooted at a different base (e.g. the
     * raw_cache-based attachments archive) are returned unchanged, since they aren't
     * relative to output.path in the first place.
     */
    public static function relativeToBase(array $config, string $path): string
    {
        $base = trim((string) ($config['output']['path'] ?? 'boarddocs-private'), '/');
        if ($base !== '' && str_starts_with($path, $base.'/')) {
            return substr($path, strlen($base) + 1);
        }

        return $path;
    }

    protected static function build(string $base, array $config, string $site, string $committeeName, string $suffix): string
    {
        $base = trim($base, '/');

        return implode('/', array_filter([
            $base,
            Urls::districtIdFromSite($site),
            $config['output']['visibility'] ?? 'Public',
            Urls::sanitizePathComponent($committeeName),
            $suffix,
        ]));
    }
}
