<?php

namespace BoardDocsScraper\Support;

/**
 * Builds the on-disk paths for a meeting, split across two trees:
 *
 *   - output.path (default "boarddocs-private"): what we produce — the
 *     merged/rewritten agenda PDF and index.jsonl.
 *   - archive.path (default "boarddocs-public"): what BoardDocs gave us,
 *     unmodified — the raw print-agenda HTML and every downloaded attachment
 *     file, kept whether or not a PDF has been built from them yet. This is
 *     also what Support\Archive reads from/writes to, so a run that starts
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
     * originally downloaded (unmodified): {archive.path}/{district}/{visibility}/{committee}/{YYYY-MM-DD}-Attachments
     */
    public static function attachmentsPath(array $config, string $site, string $committeeName, string $date): string
    {
        return self::archiveAttachmentsPath($config, $site, $committeeName, $date);
    }

    /**
     * Where a meeting's raw print-agenda HTML is archived, as originally
     * downloaded (unmodified): {archive.path}/{district}/{visibility}/{committee}/{YYYY-MM-DD}-Agenda.html
     */
    public static function archiveAgendaHtmlPath(array $config, string $site, string $committeeName, string $date): string
    {
        return self::build(
            (string) ($config['archive']['path'] ?? 'boarddocs-public'),
            $config,
            $site,
            $committeeName,
            $date.'-Agenda.html',
        );
    }

    /**
     * Where a meeting's raw attachment files (and their manifest) are kept,
     * as originally downloaded (unmodified): {archive.path}/{district}/{visibility}/{committee}/{YYYY-MM-DD}-Attachments
     */
    public static function archiveAttachmentsPath(array $config, string $site, string $committeeName, string $date): string
    {
        return self::build(
            (string) ($config['archive']['path'] ?? 'boarddocs-public'),
            $config,
            $site,
            $committeeName,
            $date.'-Attachments',
        );
    }

    /**
     * Where a site's archived committee list is kept, so `boarddocs:build` can
     * enumerate committees without any live BoardDocs request:
     * {archive.path}/{district}/committees.json
     */
    public static function archiveCommitteesPath(array $config, string $site): string
    {
        $base = trim((string) ($config['archive']['path'] ?? 'boarddocs-public'), '/');

        return implode('/', array_filter([
            $base,
            Urls::districtIdFromSite($site),
            'committees.json',
        ]));
    }

    /**
     * Where a committee's archived meeting list is kept, so `boarddocs:build`
     * can enumerate its meetings without any live BoardDocs request:
     * {archive.path}/{district}/{visibility}/{committee}/meetings.json
     */
    public static function archiveMeetingsPath(array $config, string $site, string $committeeName): string
    {
        return self::build(
            (string) ($config['archive']['path'] ?? 'boarddocs-public'),
            $config,
            $site,
            $committeeName,
            'meetings.json',
        );
    }

    /**
     * Strip the configured output base from a full storage path so it matches the
     * "path" field used in index.jsonl. Paths rooted at a different base (e.g. the
     * archive-based attachments directory) are returned unchanged, since they aren't
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
