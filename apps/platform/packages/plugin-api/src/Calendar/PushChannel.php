<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Calendar;

use Carbon\CarbonImmutable;

/**
 * A set-up notification channel.
 *
 * The expiry comes from the provider and is not assumed: Google never renews
 * on its own and promises no fixed lifetime.
 */
final readonly class PushChannel
{
    public function __construct(
        public ?string $resourceId = null,
        public ?CarbonImmutable $expiresAt = null,
    ) {}
}
