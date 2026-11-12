<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\Models\Calendar;
use App\Models\Event;
use App\Models\Household;
use App\Models\ProviderAccount;
use App\Support\HouseholdContext;
use Carbon\CarbonImmutable;
use Fahoba\PluginApi\Calendar\ConnectedCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ConnectedCalendarTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Calendar $calendar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create(['timezone' => 'Europe/Berlin']);
        app(HouseholdContext::class)->setId((int) $this->household->id);

        $account = ProviderAccount::factory()->create(['household_id' => $this->household->id]);
        $this->calendar = Calendar::factory()->create([
            'household_id' => $this->household->id,
            'provider_account_id' => $account->id,
            'external_id' => 'cal-1',
            'timezone' => 'Europe/Vienna',
        ]);
    }

    public function test_a_calendar_is_a_connected_calendar(): void
    {
        $calendar = $this->calendar->fresh();

        $this->assertInstanceOf(ConnectedCalendar::class, $calendar);
        $this->assertSame((int) $calendar->id, $calendar->calendarId());
        $this->assertSame('cal-1', $calendar->externalId());
        $this->assertSame('Europe/Vienna', $calendar->zoneName());
        $this->assertSame((int) $calendar->provider_account_id, $calendar->account()->accountId());
    }

    public function test_without_its_own_zone_the_calendar_follows_the_household(): void
    {
        $this->calendar->forceFill(['timezone' => null])->save();

        $this->assertSame('Europe/Berlin', $this->calendar->fresh()->zoneName());
    }

    public function test_known_external_ids_are_those_overlapping_the_window(): void
    {
        $from = CarbonImmutable::parse('2026-11-02 00:00', 'Europe/Vienna');
        $to = CarbonImmutable::parse('2026-11-09 00:00', 'Europe/Vienna');

        $event = fn (string $id, string $start, string $end): Event => Event::factory()->create([
            'household_id' => $this->household->id,
            'calendar_id' => $this->calendar->id,
            'external_id' => $id,
            'starts_at' => CarbonImmutable::parse($start, 'Europe/Vienna'),
            'ends_at' => CarbonImmutable::parse($end, 'Europe/Vienna'),
        ]);

        $event('inside', '2026-11-03 10:00', '2026-11-03 11:00');
        $event('across-start', '2026-11-01 23:00', '2026-11-02 01:00');
        $event('before', '2026-11-01 10:00', '2026-11-02 00:00');
        $event('after', '2026-11-09 00:00', '2026-11-09 01:00');
        Event::factory()->create([
            'household_id' => $this->household->id,
            'calendar_id' => $this->calendar->id,
            'external_id' => null,
            'starts_at' => CarbonImmutable::parse('2026-11-04 10:00', 'Europe/Vienna'),
            'ends_at' => CarbonImmutable::parse('2026-11-04 11:00', 'Europe/Vienna'),
        ]);

        $ids = $this->calendar->knownExternalIds($from, $to);
        sort($ids);

        $this->assertSame(['across-start', 'inside'], $ids);
    }

    public function test_a_new_calendar_without_attributes_does_not_loop(): void
    {
        $calendar = new Calendar;

        $this->assertNull($calendar->external_id);
        $this->assertNull($calendar->timezone);
    }
}
