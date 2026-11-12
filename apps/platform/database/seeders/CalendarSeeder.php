<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ConnectionStatus;
use App\Enums\EventOrigin;
use App\Models\Calendar;
use App\Models\Event;
use App\Models\Household;
use App\Models\Person;
use App\Models\ProviderAccount;
use App\Support\HouseholdContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Sample calendars with events around today.
 *
 * The source is deliberately NOT connected: there are no tokens, and the sync
 * skips it because it only queues active sources. The calendars stay enabled
 * so the wall actually shows the data.
 */
final class CalendarSeeder extends Seeder
{
    private const ACCOUNT_LABEL = 'beispiel@fahoba.invalid';

    public function run(): void
    {
        $household = Household::query()->orderBy('id')->first();

        if ($household === null) {
            return;
        }

        app(HouseholdContext::class)->setId((int) $household->id);

        /*
         * Start over on every run: the sample events sit around today, so a
         * second run should move them instead of failing on the unique key.
         * Calendars and events go with the account via cascading deletes.
         */
        ProviderAccount::query()
            ->where('provider', 'google')
            ->where('account_label', self::ACCOUNT_LABEL)
            ->delete();

        $source = ProviderAccount::create([
            'provider' => 'google',
            'account_label' => self::ACCOUNT_LABEL,
            'status' => ConnectionStatus::InvalidGrant,
        ]);

        $people = Person::query()->get()->keyBy('name');

        $family = Calendar::create([
            'provider_account_id' => $source->id,
            'external_id' => 'familie@group.calendar.google.invalid',
            'summary' => 'Familie',
            'color' => '#616161',
            'timezone' => $household->timezone,
            'is_active' => true,
        ]);

        $calendarsByPerson = [];

        foreach (['Alex' => '#1E88E5', 'Sam' => '#8E24AA', 'Max' => '#2E7D32', 'Lena' => '#EF6C00'] as $name => $color) {
            $person = $people->get($name);

            if ($person === null) {
                continue;
            }

            $calendarsByPerson[$name] = Calendar::create([
                'provider_account_id' => $source->id,
                'external_id' => mb_strtolower($name).'@fahoba.invalid',
                'summary' => $name,
                'color' => $color,
                'timezone' => $household->timezone,
                'is_active' => true,
            ]);
            $calendarsByPerson[$name]->people()->attach($person);
        }

        $today = CarbonImmutable::now($household->timezone)->startOfDay();

        $events = [
            ['Familie', 'Großeltern zu Besuch', $today->addDays(2)->setTime(15, 0), 180, false],
            ['Familie', 'Wandertag', $today->addDays(5), 0, true],
            ['Alex', 'Teambesprechung', $today->setTime(9, 30), 60, false],
            ['Alex', 'Zahnarzt', $today->addDays(1)->setTime(14, 15), 45, false],
            ['Sam', 'Yoga', $today->addDays(1)->setTime(18, 0), 75, false],
            ['Sam', 'Elternabend', $today->addDays(3)->setTime(19, 0), 90, false],
            ['Max', 'Fußballtraining', $today->setTime(16, 30), 90, false],
            ['Max', 'Schulausflug', $today->addDays(4), 0, true],
            ['Lena', 'Musikschule', $today->addDays(2)->setTime(16, 0), 45, false],
        ];

        foreach ($events as [$calendarName, $title, $start, $duration, $allDay]) {
            $calendar = $calendarName === 'Familie'
                ? $family
                : ($calendarsByPerson[$calendarName] ?? null);

            if ($calendar === null) {
                continue;
            }

            Event::create([
                'calendar_id' => $calendar->id,
                'title' => $title,
                'starts_at' => $start,
                'ends_at' => $allDay ? $start->addDay() : $start->addMinutes($duration),
                'all_day' => $allDay,
                'origin' => EventOrigin::Remote,
            ]);
        }
    }
}
