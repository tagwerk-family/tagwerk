<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Calendar;
use App\Models\ProviderAccount;
use App\Services\Calendar\CalendarSyncService;
use Fahoba\PluginApi\Connections\AuthorizationLost;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Syncs one calendar.
 *
 * ShouldBeUnique because Google sends several notifications in quick
 * succession during a wave of changes, and one sync catches up on the whole
 * backlog anyway.
 */
final class SyncCalendarJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $calendarId) {}

    public function uniqueId(): string
    {
        return (string) $this->calendarId;
    }

    public function handle(CalendarSyncService $sync): void
    {
        /*
         * Without withoutHouseholdScope the queue worker would find nothing:
         * its context is empty and the global scope filters on NULL.
         *
         * The source relation is deliberately NOT loaded here — it carries
         * the same scope and would come back null. The sync loads it itself
         * after setting the context.
         */
        $calendar = Calendar::withoutHouseholdScope()->find($this->calendarId);

        if ($calendar === null || ! $calendar->is_active) {
            return;
        }

        try {
            $sync->sync($calendar);
        } catch (AuthorizationLost $exception) {
            /*
             * No retry: the source is already marked invalid_grant, and a
             * second run would end the same way. The wall tablet shows a
             * banner for it.
             */
            Log::warning('Calendar sync aborted, account needs reconnecting.', [
                'calendar_id' => $this->calendarId,
                'account' => ProviderAccount::of($exception->source)->account_label,
            ]);
        }
    }
}
