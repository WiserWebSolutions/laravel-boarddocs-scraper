<?php

namespace BoardDocsScraper\Data;

/**
 * A single meeting entry returned by the BD-GetMeetingsList endpoint.
 * Mirrors the `Meeting` dataclass in the Python exporter.
 */
class MeetingData
{
    public function __construct(
        public readonly string $unique,
        public readonly string $name,
        public readonly string $numberdate,
        public readonly string $unid = '',
    ) {}

    /**
     * Convert the BoardDocs "numberdate" (YYYYMMDD) into an ISO date (YYYY-MM-DD).
     */
    public function isoDate(): string
    {
        $d = $this->numberdate;

        return substr($d, 0, 4).'-'.substr($d, 4, 2).'-'.substr($d, 6, 2);
    }

    public function toArray(): array
    {
        return [
            'unique' => $this->unique,
            'name' => $this->name,
            'numberdate' => $this->numberdate,
            'unid' => $this->unid,
            'date' => $this->isoDate(),
        ];
    }

    /**
     * Rehydrate from the array shape produced by {@see toArray()}. Used to keep
     * cached payloads free of serialized objects (see BoardDocsClient caching).
     * The derived "date" key is ignored.
     *
     * @param  array{unique: string, name: string, numberdate: string, unid?: string}  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            unique: (string) $row['unique'],
            name: (string) $row['name'],
            numberdate: (string) $row['numberdate'],
            unid: (string) ($row['unid'] ?? ''),
        );
    }
}
