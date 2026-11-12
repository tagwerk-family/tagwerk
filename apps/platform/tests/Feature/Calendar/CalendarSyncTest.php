<?php

declare(strict_types=1);

namespace Tests\Feature\Calendar;

use App\Enums\EventOrigin;
use App\Models\Calendar;
use App\Models\Event;
use App\Models\Household;
use App\Models\ProviderAccount;
use App\Services\Calendar\CalendarSyncService;
use App\Services\Calendar\SyncResult;
use App\Support\HouseholdContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class CalendarSyncTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Calendar $calendar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();

        app(HouseholdContext::class)->setId((int) $this->household->id);

        $source = ProviderAccount::factory()->create([
            'household_id' => $this->household->id,
        ]);

        $this->calendar = Calendar::factory()->create([
            'household_id' => $this->household->id,
            'provider_account_id' => $source->id,
            'external_id' => 'familie@group.calendar.google.com',
            'timezone' => 'Europe/Vienna',
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function page(array $items, ?string $syncToken = 'TOKEN-NEW', ?string $pageToken = null): array
    {
        $page = ['kind' => 'calendar#events', 'items' => $items];

        if ($pageToken !== null) {
            $page['nextPageToken'] = $pageToken;

            return $page;
        }

        $page['nextSyncToken'] = $syncToken;

        return $page;
    }

    /**
     * @return array<string, mixed>
     */
    private function event(string $id, string $title = 'Zahnarzt'): array
    {
        return [
            'id' => $id,
            'status' => 'confirmed',
            'etag' => '"3412345678901234"',
            'summary' => $title,
            'location' => 'Ordination Dr. Berger',
            'start' => ['dateTime' => '2026-09-20T09:00:00+02:00', 'timeZone' => 'Europe/Vienna'],
            'end' => ['dateTime' => '2026-09-20T10:00:00+02:00', 'timeZone' => 'Europe/Vienna'],
            'updated' => '2026-09-14T08:00:00.000Z',
        ];
    }

    private function sync(): SyncResult
    {
        return app(CalendarSyncService::class)->sync($this->calendar);
    }

    public function test_new_events_are_imported(): void
    {
        Http::fake([
            'www.googleapis.com/*' => Http::response($this->page([
                $this->event('evt-1', 'Zahnarzt'),
                $this->event('evt-2', 'Elternabend'),
            ])),
        ]);

        $result = $this->sync();

        $this->assertSame(2, $result->created);
        $this->assertSame(2, Event::count());

        $event = Event::where('external_id', 'evt-1')->firstOrFail();
        $this->assertSame('Zahnarzt', $event->title);
        $this->assertSame('Ordination Dr. Berger', $event->location);
        $this->assertSame(EventOrigin::Remote, $event->origin);

        $this->assertSame('TOKEN-NEW', $this->calendar->fresh()->sync_cursor);
    }

    /**
     * The first sync fetches half a year at once. One query per event to see
     * whether it already exists would mean hundreds of database round trips.
     */
    public function test_a_page_looks_up_existing_events_in_one_query(): void
    {
        Http::fake([
            'www.googleapis.com/*' => Http::response($this->page(
                array_map(fn (int $number): array => $this->event("evt-{$number}"), range(1, 20)),
            )),
        ]);

        $lookups = 0;

        DB::listen(function (QueryExecuted $query) use (&$lookups): void {
            if (str_starts_with($query->sql, 'select') && str_contains($query->sql, 'external_id')) {
                $lookups++;
            }
        });

        $this->assertSame(20, $this->sync()->created);
        $this->assertSame(1, $lookups);
    }

    public function test_all_day_events_end_on_the_following_day(): void
    {
        Http::fake([
            'www.googleapis.com/*' => Http::response($this->page([[
                'id' => 'evt-all-day',
                'status' => 'confirmed',
                'summary' => 'Schulausflug',
                'start' => ['date' => '2026-09-20'],
                'end' => ['date' => '2026-09-21'],
            ]])),
        ]);

        $this->sync();

        $event = Event::where('external_id', 'evt-all-day')->firstOrFail();

        $this->assertTrue($event->all_day);
        $this->assertSame('2026-09-20', $event->starts_at->setTimezone('Europe/Vienna')->toDateString());
        $this->assertSame('2026-09-21', $event->ends_at->setTimezone('Europe/Vienna')->toDateString());

        /*
         * Not just the date but the instant: an all-day event starts at
         * midnight in the calendar's time zone. An instant shifted by the zone
         * offset keeps its date and therefore went unnoticed here at first --
         * on the wall it then occupied two cells.
         */
        $this->assertSame('00:00', $event->starts_at->setTimezone('Europe/Vienna')->format('H:i'));
        $this->assertSame('00:00', $event->ends_at->setTimezone('Europe/Vienna')->format('H:i'));
    }

    public function test_cancelled_events_disappear(): void
    {
        Event::factory()->create([
            'household_id' => $this->household->id,
            'calendar_id' => $this->calendar->id,
            'external_id' => 'evt-gone',
        ]);

        Http::fake([
            'www.googleapis.com/*' => Http::response($this->page([
                ['id' => 'evt-gone', 'status' => 'cancelled'],
            ])),
        ]);

        $result = $this->sync();

        $this->assertSame(1, $result->deleted);
        $this->assertSame(0, Event::count());
    }

    public function test_incremental_fetch_sends_the_sync_token_without_time_window(): void
    {
        $this->calendar->forceFill(['sync_cursor' => 'TOKEN-OLD'])->save();

        Http::fake([
            'www.googleapis.com/*' => Http::response($this->page([])),
        ]);

        $this->sync();

        Http::assertSent(function (Request $request): bool {
            // Google rejects syncToken combined with timeMin.
            return str_contains($request->url(), 'syncToken=TOKEN-OLD')
                && ! str_contains($request->url(), 'timeMin');
        });
    }

    public function test_paged_fetch_follows_the_page_token(): void
    {
        Http::fake([
            'www.googleapis.com/*' => Http::sequence()
                ->push($this->page([$this->event('evt-1')], pageToken: 'PAGE-2'))
                ->push($this->page([$this->event('evt-2'), $this->event('evt-3')], syncToken: 'TOKEN-END')),
        ]);

        $result = $this->sync();

        $this->assertSame(3, $result->created);

        // Google only delivers the syncToken on the last page.
        $this->assertSame('TOKEN-END', $this->calendar->fresh()->sync_cursor);
    }

    /**
     * Mandatory path 1 of the spec: 410 Gone is a regular path, not a
     * failure.
     */
    public function test_410_triggers_full_resync_without_data_loss(): void
    {
        $this->calendar->forceFill(['sync_cursor' => 'TOKEN-EXPIRED'])->save();

        // A stale Google event that in truth no longer exists.
        Event::factory()->create([
            'household_id' => $this->household->id,
            'calendar_id' => $this->calendar->id,
            'external_id' => 'evt-stale',
        ]);

        // And one created in Fahoba that Google does not know at all.
        $own = Event::factory()->fromApp()->create([
            'household_id' => $this->household->id,
            'calendar_id' => $this->calendar->id,
            'external_id' => null,
            'title' => 'Mahlzeitenplan',
        ]);

        Http::fake([
            'www.googleapis.com/*' => Http::sequence()
                ->push(['error' => ['code' => 410, 'message' => 'Sync token is no longer valid']], 410)
                ->push($this->page([$this->event('evt-new', 'Turnen')], syncToken: 'TOKEN-FRESH')),
        ]);

        $result = $this->sync();

        $this->assertTrue($result->fullResync);
        $this->assertSame(1, $result->created);

        // The stale Google event is gone, our own one survived.
        $this->assertNull(Event::where('external_id', 'evt-stale')->first());
        $this->assertNotNull($own->fresh());
        $this->assertNotNull(Event::where('external_id', 'evt-new')->first());

        $this->assertSame('TOKEN-FRESH', $this->calendar->fresh()->sync_cursor);
    }

    /**
     * Mandatory path 2: our own write must not trigger a loop.
     */
    public function test_own_write_returns_without_effect(): void
    {
        Event::factory()->fromApp('rev-7')->create([
            'household_id' => $this->household->id,
            'calendar_id' => $this->calendar->id,
            'external_id' => 'evt-own',
            'title' => 'Ursprünglicher Titel',
        ]);

        Http::fake([
            'www.googleapis.com/*' => Http::response($this->page([[
                ...$this->event('evt-own', 'Von Google zurückgespielt'),
                'extendedProperties' => ['private' => ['fahoba_revision' => 'rev-7']],
            ]])),
        ]);

        $result = $this->sync();

        $this->assertSame(1, $result->skipped);
        $this->assertSame(0, $result->updated);

        $event = Event::where('external_id', 'evt-own')->firstOrFail();
        $this->assertSame('Ursprünglicher Titel', $event->title);
        $this->assertSame(EventOrigin::App, $event->origin);
    }

    public function test_foreign_revision_marker_is_applied(): void
    {
        Event::factory()->fromApp('rev-7')->create([
            'household_id' => $this->household->id,
            'calendar_id' => $this->calendar->id,
            'external_id' => 'evt-own',
            'title' => 'Ursprünglicher Titel',
        ]);

        Http::fake([
            'www.googleapis.com/*' => Http::response($this->page([[
                ...$this->event('evt-own', 'Am Handy geändert'),
                'extendedProperties' => ['private' => ['fahoba_revision' => 'rev-8']],
            ]])),
        ]);

        $result = $this->sync();

        $this->assertSame(1, $result->updated);
        $this->assertSame(
            'Am Handy geändert',
            Event::where('external_id', 'evt-own')->firstOrFail()->title,
        );
    }
}
