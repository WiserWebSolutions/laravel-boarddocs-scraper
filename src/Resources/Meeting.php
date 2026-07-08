<?php

namespace BoardDocsScraper\Resources;

use BoardDocsScraper\Client\BoardDocsClient;
use BoardDocsScraper\Data\MeetingData;

/**
 * A single meeting belonging to a committee.
 */
class Meeting
{
    public function __construct(
        protected Committee $committee,
        protected BoardDocsClient $client,
        protected array $config,
        protected MeetingData $data,
    ) {
    }

    public function committee(): Committee
    {
        return $this->committee;
    }

    public function data(): MeetingData
    {
        return $this->data;
    }

    public function unique(): string
    {
        return $this->data->unique;
    }

    public function name(): string
    {
        return $this->data->name;
    }

    public function date(): string
    {
        return $this->data->isoDate();
    }

    public function numberdate(): string
    {
        return $this->data->numberdate;
    }

    /**
     * The agenda for this meeting.
     */
    public function agenda(): Agenda
    {
        return new Agenda($this, $this->client, $this->config);
    }

    public function toArray(): array
    {
        return $this->data->toArray();
    }
}
