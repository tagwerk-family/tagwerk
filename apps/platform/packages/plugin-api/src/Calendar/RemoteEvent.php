<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Calendar;

use Carbon\CarbonImmutable;

/**
 * An event as the provider reports it — already translated.
 *
 * The translation lies with the provider, not with the sync: a cancellation
 * arrives differently everywhere (Google reports `status: cancelled`, CalDAV
 * answers with 404), and Fahoba mirrors the person mapping into a field
 * every provider names differently.
 */
final readonly class RemoteEvent
{
    /**
     * @param  string|null  $version  state for optimistic locking (the ETag at Google)
     * @param  array<int, int>|null  $personIds  null means: not stated, leave the existing mapping untouched
     */
    public function __construct(
        public string $externalId,
        public string $title = '',
        public ?string $description = null,
        public ?string $location = null,
        public ?CarbonImmutable $startsAt = null,
        public ?CarbonImmutable $endsAt = null,
        public bool $allDay = false,
        public ?string $version = null,
        public ?CarbonImmutable $updatedAt = null,
        public ?string $recurringEventId = null,
        public ?string $revisionMarker = null,
        public ?array $personIds = null,
        public bool $cancelled = false,
    ) {}

    /** A cancellation carries nothing usable besides the ID. */
    public static function cancelled(string $externalId): self
    {
        return new self($externalId, cancelled: true);
    }
}
