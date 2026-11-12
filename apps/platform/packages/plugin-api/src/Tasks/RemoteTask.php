<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Tasks;

use Carbon\CarbonImmutable;

/**
 * A task as it comes in from outside.
 *
 * Deliberately narrow: title, note, due date, done. What Fahoba makes of it
 * — points, assignee, rotation — is not here, because no external service
 * has it. The household lays these fields on top, and a sync never touches
 * them.
 */
final readonly class RemoteTask
{
    public function __construct(
        public string $externalId,
        public string $title,
        public ?string $notes = null,
        public ?CarbonImmutable $dueOn = null,
        public ?CarbonImmutable $completedAt = null,

        /**
         * The state at the provider. If it does not change, the row stays
         * untouched — otherwise every sync would overwrite the enrichment
         * with the same values and create write load for nothing.
         */
        public ?string $version = null,
    ) {}

    public function isDone(): bool
    {
        return $this->completedAt !== null;
    }
}
