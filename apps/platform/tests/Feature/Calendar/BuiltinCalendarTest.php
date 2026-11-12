<?php

declare(strict_types=1);

namespace Tests\Feature\Calendar;

use App\Enums\EventOrigin;
use App\Integrations\Calendar\Builtin\BuiltinCalendarProvider;
use App\Jobs\SyncCalendarJob;
use App\Models\Calendar;
use App\Models\Event;
use App\Models\Household;
use App\Services\Calendar\CalendarSyncService;
use App\Services\DevicePairingService;
use App\Support\HouseholdContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Fahoba without an external service.
 *
 * This shows whether the provider layer holds up: the builtin calendar brings
 * not a single capability. If anywhere in the write path, the sync or the
 * background runs a call comes up that it does not know, one of these tests
 * fails — and not only the household that uses Fahoba without a connected
 * account.
 */
final class BuiltinCalendarTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create(['timezone' => 'Europe/Vienna']);

        app(HouseholdContext::class)->setId((int) $this->household->id);

        $device = app(DevicePairingService::class)->createCode($this->household, 'Küche');
        $this->token = app(DevicePairingService::class)->redeem($device->pairing_code)['token'];
    }

    public function test_every_household_gets_a_calendar_without_external_service(): void
    {
        $calendar = $this->builtinCalendar();

        $this->assertSame(BuiltinCalendarProvider::KEY, $calendar->source->provider);
        $this->assertTrue($calendar->is_active);
        $this->assertNull($calendar->source->access_token);
    }

    public function test_event_is_created_without_anything_going_out(): void
    {
        Http::preventStrayRequests();

        $calendar = $this->builtinCalendar();

        $this->withToken($this->token)
            ->postJson('/api/wall/events', [
                'calendar_id' => $calendar->id,
                'title' => 'Zahnarzt',
                'starts_at' => '2026-09-24T09:00:00+02:00',
                'ends_at' => '2026-09-24T10:00:00+02:00',
                'all_day' => false,
            ])
            ->assertCreated()
            ->assertJsonPath('title', 'Zahnarzt');

        $event = Event::query()->firstOrFail();

        $this->assertSame(EventOrigin::App, $event->origin);

        // No external id, no external version: the event lives only here.
        $this->assertNull($event->external_id);
        $this->assertNull($event->external_version);
    }

    public function test_event_appears_on_the_wall(): void
    {
        Http::preventStrayRequests();

        $calendar = $this->builtinCalendar();

        Event::factory()->create([
            'household_id' => $this->household->id,
            'calendar_id' => $calendar->id,
            'origin' => EventOrigin::App,
            'external_id' => null,
            'title' => 'Elternabend',
            'starts_at' => '2026-09-16T18:00:00+02:00',
            'ends_at' => '2026-09-16T20:00:00+02:00',
        ]);

        $this->withToken($this->token)
            ->getJson('/api/wall/agenda?from=2026-09-16&to=2026-09-16')
            ->assertSuccessful()
            ->assertJsonPath('events.0.title', 'Elternabend');
    }

    public function test_sync_runs_empty_instead_of_failing(): void
    {
        Http::preventStrayRequests();

        $result = app(CalendarSyncService::class)->sync($this->builtinCalendar());

        $this->assertSame(0, $result->touched());
        $this->assertFalse($result->fullResync);
    }

    public function test_background_run_does_not_even_queue_it(): void
    {
        Queue::fake();

        $this->artisan('fahoba:sync-calendars')->assertSuccessful();

        Queue::assertNotPushed(SyncCalendarJob::class);
    }

    public function test_channels_are_not_renewed_for_it(): void
    {
        Http::preventStrayRequests();

        $this->artisan('fahoba:renew-push-channels')
            ->expectsOutputToContain('Renewed 0 channels.')
            ->assertSuccessful();
    }

    private function builtinCalendar(): Calendar
    {
        return Calendar::query()
            ->whereHas('source', fn ($source) => $source->where('provider', BuiltinCalendarProvider::KEY))
            ->with('source')
            ->firstOrFail();
    }
}
