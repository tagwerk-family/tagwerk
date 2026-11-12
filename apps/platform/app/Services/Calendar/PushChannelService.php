<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Integrations\ProviderRegistry;
use App\Models\Calendar;
use DateInterval;
use Fahoba\PluginApi\Calendar\WatchesChanges;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Creating and renewing notification channels.
 *
 * Channels never renew themselves, and there is no fixed promise about their
 * lifetime — only the expiry the provider names on setup counts.
 */
final class PushChannelService
{
    /** Lead time with which an expiring channel is replaced. */
    private const RENEW_LEAD = 'P2D';

    public function __construct(private readonly ProviderRegistry $registry) {}

    /**
     * Creates a new channel when needed.
     *
     * @return bool whether a channel was created
     */
    public function ensure(Calendar $calendar): bool
    {
        $provider = $this->registry->calendarFor($calendar->source);

        // Without notifications the regular polling remains — nothing to do.
        if (! $provider instanceof WatchesChanges || ! $this->needsRenewal($calendar)) {
            return false;
        }

        $previousChannel = $calendar->push_channel_id;
        $previousResource = $calendar->push_resource_id;

        $channelId = (string) Str::uuid();
        $channel = $provider->watch($calendar, $channelId);

        $calendar->forceFill([
            'push_channel_id' => $channelId,
            'push_resource_id' => $channel->resourceId,
            'push_expires_at' => $channel->expiresAt,
        ])->save();

        if ($previousChannel !== null && $previousResource !== null) {
            $this->stopPrevious($provider, $calendar, $previousChannel, $previousResource);
        }

        return true;
    }

    public function needsRenewal(Calendar $calendar): bool
    {
        return ! $calendar->hasPushChannel()
            || $calendar->pushExpiresWithin(new DateInterval(self::RENEW_LEAD));
    }

    /**
     * The old channel is unsubscribed, but its failure is not passed on: an
     * overlap is harmless per the design, and an already expired channel can
     * no longer be stopped.
     */
    private function stopPrevious(
        WatchesChanges $provider,
        Calendar $calendar,
        string $channelId,
        string $resourceId,
    ): void {
        try {
            $provider->unwatch($calendar->source, $channelId, $resourceId);
        } catch (Throwable $exception) {
            Log::warning('Could not stop the old notification channel.', [
                'calendar_id' => $calendar->id,
                'channel_id' => $channelId,
                'reason' => $exception->getMessage(),
            ]);
        }
    }
}
