<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Calendar;

/** A calendar as the provider reports it. */
final readonly class RemoteCalendar
{
    public function __construct(
        public string $externalId,
        public string $title,
        public ?string $color = null,
        public ?string $timezone = null,
        public bool $isPrimary = false,
    ) {}
}
