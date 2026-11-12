<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Calendar;

use Fahoba\PluginApi\Connections\ConnectedAccount;
use Illuminate\Http\Request;

/**
 * Capability: the provider reports when something has changed.
 *
 * Without it only regular polling remains — entirely sufficient for a
 * calendar that rarely changes.
 */
interface WatchesChanges
{
    /** @param  string  $channelId  assigned by Fahoba so the notification can be matched */
    public function watch(ConnectedCalendar $calendar, string $channelId): PushChannel;

    public function unwatch(ConnectedAccount $source, string $channelId, string $resourceId): void;

    /**
     * Checks an incoming notification and names the affected channel.
     *
     * Every provider proves differently that a notification comes from it —
     * via a header, a signature, a shared secret. So it checks itself, and the
     * controller stays free of that.
     *
     * null means: genuine, but nothing to do (an acknowledgement, say).
     *
     * @throws InvalidNotification when the notification is not genuine
     */
    public function channelFromNotification(Request $request): ?string;
}
