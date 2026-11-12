<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Integrations\ProviderRegistry;
use App\Jobs\SyncCalendarJob;
use App\Models\Calendar;
use App\Models\Household;
use Fahoba\PluginApi\Calendar\InvalidNotification;
use Fahoba\PluginApi\Calendar\WatchesChanges;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Receives the providers' change notifications.
 *
 * The notification itself carries no data — it is only a trigger. The actual
 * fetch happens in the job.
 *
 * How a notification proves it is genuine only the provider knows; the
 * controller knows neither headers nor signatures.
 */
final class CalendarWebhookController extends Controller
{
    public function __invoke(Request $request, string $provider, ProviderRegistry $registry): Response
    {
        $calendarProvider = $registry->calendarProvider($provider);

        if (! $calendarProvider instanceof WatchesChanges) {
            return response()->noContent(Response::HTTP_NOT_FOUND);
        }

        try {
            $channel = $calendarProvider->channelFromNotification($request);
        } catch (InvalidNotification) {
            return response()->noContent(Response::HTTP_FORBIDDEN);
        }

        if ($channel === null) {
            return response()->noContent();
        }

        // No household context: the call comes from outside, not from us.
        $calendar = Calendar::withoutHouseholdScope()
            ->where('push_channel_id', $channel)
            ->whereIn('household_id', Household::writable()->select('id'))
            ->first();

        /*
         * Unknown channel or a read-only household: answer with 204.
         * Anything outside 2xx makes the provider deliver again.
         */
        if ($calendar !== null) {
            SyncCalendarJob::dispatch($calendar->id);
        }

        return response()->noContent();
    }
}
