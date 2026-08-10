<?php

namespace BoardDocsScraper\Support;

/**
 * Builds the on-disk export paths, mirroring the original project layout:
 *   {output.path}/{district}/{visibility}/{committee}/{YYYY-MM-DD}-Agenda.pdf
 *
 * The same layout, rooted at raw_cache.path instead, is used to persist the
 * raw agenda HTML and attachment files BoardDocs returns for a meeting that
 * hasn't been exported yet (see Support\RawCache) — so a run that starts
 * getting 403s can finish building PDFs from what's already on disk.
 */
class OutputPaths
{
    public static function meetingPath(array $config, string $site, string $committeeName, string $date): string
    {
        return self::build(
            (string) ($config['output']['path'] ?? 'boarddocs'),
            $config,
            $site,
            $committeeName,
            $date.'-Agenda.pdf',
        );
    }

    /**
     * The directory a meeting's raw attachment files are archived under, next
     * to its agenda PDF: {output.path}/{district}/{visibility}/{committee}/{YYYY-MM-DD}-Attachments
     */
    public static function attachmentsPath(array $config, string $site, string $committeeName, string $date): string
    {
        return self::build(
            (string) ($config['output']['path'] ?? 'boarddocs'),
            $config,
            $site,
            $committeeName,
            $date.'-Attachments',
        );
    }

    /**
     * Where a not-yet-exported meeting's raw print-agenda HTML is cached:
     * {raw_cache.path}/{district}/{visibility}/{committee}/{YYYY-MM-DD}-Agenda.html
     */
    public static function rawAgendaHtmlPath(array $config, string $site, string $committeeName, string $date): string
    {
        return self::build(
            (string) ($config['raw_cache']['path'] ?? 'private/boarddocs'),
            $config,
            $site,
            $committeeName,
            $date.'-Agenda.html',
        );
    }

    /**
     * Where a not-yet-exported meeting's raw attachment files (and their
     * manifest) are cached, mirroring attachmentsPath() but rooted at
     * raw_cache.path instead of output.path.
     */
    public static function rawAttachmentsPath(array $config, string $site, string $committeeName, string $date): string
    {
        return self::build(
            (string) ($config['raw_cache']['path'] ?? 'private/boarddocs'),
            $config,
            $site,
            $committeeName,
            $date.'-Attachments',
        );
    }

    /**
     * Strip the configured output base from a full storage path so it matches the
     * "path" field used in index.jsonl.
     */
    public static function relativeToBase(array $config, string $path): string
    {
        $base = trim((string) ($config['output']['path'] ?? 'boarddocs'), '/');
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
