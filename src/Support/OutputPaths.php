<?php

namespace BoardDocsScraper\Support;

/**
 * Builds the on-disk export paths, mirroring the original project layout:
 *   {output.path}/{district}/{visibility}/{committee}/{YYYY-MM-DD}-Agenda.pdf
 */
class OutputPaths
{
    public static function meetingPath(array $config, string $site, string $committeeName, string $date): string
    {
        $base = trim((string) ($config['output']['path'] ?? 'boarddocs'), '/');

        return implode('/', array_filter([
            $base,
            Urls::districtIdFromSite($site),
            $config['output']['visibility'] ?? 'Public',
            Urls::sanitizePathComponent($committeeName),
            $date.'-Agenda.pdf',
        ]));
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
}
