<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Calendar;

use Carbon\CarbonImmutable;

/** What is to be written. */
final readonly class EventDraft
{
    /**
     * @param  array<int, int>  $personIds
     * @param  string  $revisionMarker  marker of our own write; when it comes back via a
     *                                  notification it is recognised by it and triggers
     *                                  no further write
     */
    public function __construct(
        public string $title,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public bool $allDay,
        public ?string $description,
        public ?string $location,
        public array $personIds,
        public string $revisionMarker,
    ) {}
}
