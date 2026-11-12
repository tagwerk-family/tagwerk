<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Enums\ConnectionStatus;
use App\Enums\ProviderArea;
use App\Integrations\Calendar\Builtin\BuiltinCalendarProvider;
use App\Models\Calendar;
use App\Models\Household;
use App\Models\ProviderAccount;

/**
 * Makes sure every household has a calendar that works without a third-party
 * service.
 *
 * Without it a freshly set up Fahoba would be empty and stay that way until
 * someone connects a foreign account — the wall would show a calendar nothing
 * can be entered into.
 *
 * Idempotent, so that the call from the model event, the migration and the
 * seeder hits the same calendar instead of creating three.
 */
final class BuiltinCalendar
{
    /** External ID of the built-in calendar — there is only one per household. */
    private const EXTERNAL_ID = 'fahoba';

    public function ensureFor(Household $household): Calendar
    {
        /*
         * Without withoutHouseholdScope an existing source would go unnoticed
         * on creation: the scope filters by the context currently set, and
         * while a household is being created that is a different one.
         */
        $source = ProviderAccount::withoutHouseholdScope()->firstOrCreate(
            [
                'household_id' => $household->id,
                'area' => ProviderArea::Calendar,
                'provider' => BuiltinCalendarProvider::KEY,
            ],
            [
                'account_label' => 'Family Home Base',
                'status' => ConnectionStatus::Active,
            ],
        );

        return Calendar::withoutHouseholdScope()->firstOrCreate(
            [
                'provider_account_id' => $source->id,
                'external_id' => self::EXTERNAL_ID,
            ],
            [
                'household_id' => $household->id,
                'summary' => __('Family'),
                'color' => '#0284c7',
                'timezone' => $household->timezone,
                'is_primary' => true,
                'is_active' => true,
            ],
        );
    }
}
