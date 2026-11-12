<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\Models\Calendar;
use App\Models\Event;
use App\Models\Household;
use App\Models\Person;
use App\Models\SchoolClosure;
use App\Models\User;
use App\Support\HouseholdContext;
use Carbon\CarbonImmutable;
use Fahoba\IcsFeed\Filament\IcsFeedResource;
use Fahoba\IcsFeed\Filament\ManageIcsFeeds;
use Fahoba\IcsFeed\IcsFeed;
use Fahoba\IcsFeed\IcsFeedArea;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Sabre\VObject\Reader;
use Tests\TestCase;

/**
 * A secret address for other calendar apps: the family's events, or one
 * person's, read-only. Revoked or switched off, the address is gone.
 */
final class IcsFeedTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Person $lena;

    private Person $max;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00', 'Europe/Vienna'));
        $this->household = Household::factory()->create(['timezone' => 'Europe/Vienna']);
        $this->optIn($this->household, IcsFeedArea::KEY);
        app(HouseholdContext::class)->setId((int) $this->household->id);

        $this->lena = Person::factory()->child()->create(['household_id' => $this->household->id, 'name' => 'Lena']);
        $this->max = Person::factory()->child()->create(['household_id' => $this->household->id, 'name' => 'Max']);
        $calendar = Calendar::factory()->create(['household_id' => $this->household->id, 'is_active' => true]);

        Event::factory()->create([
            'household_id' => $this->household->id,
            'calendar_id' => $calendar->id,
            'title' => 'Turnen',
            'starts_at' => CarbonImmutable::parse('2026-10-22 16:00', 'Europe/Vienna'),
            'ends_at' => CarbonImmutable::parse('2026-10-22 17:00', 'Europe/Vienna'),
            'all_day' => false,
        ])->people()->attach($this->lena->id);

        Event::factory()->create([
            'household_id' => $this->household->id,
            'calendar_id' => $calendar->id,
            'title' => 'Ausflug',
            'starts_at' => CarbonImmutable::parse('2026-10-24 00:00', 'Europe/Vienna'),
            'ends_at' => CarbonImmutable::parse('2026-10-25 00:00', 'Europe/Vienna'),
            'all_day' => true,
        ])->people()->attach($this->max->id);

        Event::factory()->create([
            'household_id' => $this->household->id,
            'calendar_id' => $calendar->id,
            'title' => 'Vor zwei Jahren',
            'starts_at' => CarbonImmutable::parse('2024-10-22 16:00', 'Europe/Vienna'),
            'ends_at' => CarbonImmutable::parse('2024-10-22 17:00', 'Europe/Vienna'),
        ]);

        SchoolClosure::query()->create(['household_id' => $this->household->id, 'title' => 'Herbstferien', 'starts_on' => '2026-10-26', 'ends_on' => '2026-10-31']);
    }

    /** @return array{0: IcsFeed, 1: string} */
    private function feed(array $attributes = []): array
    {
        $feed = IcsFeed::query()->create(['household_id' => $this->household->id, 'name' => 'Familie', ...$attributes]);
        $address = $feed->renew();

        return [$feed, parse_url($address, PHP_URL_PATH)];
    }

    /** @return array<string, mixed> SUMMARY → VEVENT */
    private function events(string $path): array
    {
        $response = $this->get($path)->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8');
        $calendar = Reader::read((string) $response->getContent());

        $events = [];

        foreach ($calendar->select('VEVENT') as $event) {
            $events[(string) $event->SUMMARY] = $event;
        }

        return $events;
    }

    public function test_the_family_feed_carries_the_coming_events(): void
    {
        [, $path] = $this->feed();
        $events = $this->events($path);

        $this->assertSame(['Turnen', 'Ausflug'], array_keys($events));
        $this->assertSame('2026-10-22T14:00:00+00:00', $events['Turnen']->DTSTART->getDateTime()->format(DATE_ATOM));
    }

    public function test_an_all_day_event_stays_on_its_day(): void
    {
        [, $path] = $this->feed();
        $trip = $this->events($path)['Ausflug'];

        $this->assertFalse($trip->DTSTART->hasTime());
        $this->assertSame('20261024', (string) $trip->DTSTART);
        $this->assertSame('20261025', (string) $trip->DTEND);
    }

    public function test_a_person_feed_carries_only_their_events(): void
    {
        [, $path] = $this->feed(['person_id' => $this->lena->id]);

        $this->assertSame(['Turnen'], array_keys($this->events($path)));
    }

    public function test_holidays_come_along_when_asked(): void
    {
        [, $path] = $this->feed(['include_closures' => true]);
        $holidays = $this->events($path)['Herbstferien'];

        $this->assertSame('20261026', (string) $holidays->DTSTART);
        $this->assertSame('20261101', (string) $holidays->DTEND);
    }

    public function test_a_renewed_address_replaces_the_old_one(): void
    {
        [$feed, $old] = $this->feed();
        $new = parse_url($feed->renew(), PHP_URL_PATH);

        $this->get($old)->assertNotFound();
        $this->get($new)->assertOk();
    }

    public function test_switched_off_the_address_is_gone(): void
    {
        [, $path] = $this->feed();
        $this->household->update(['opted_in_features' => []]);

        $this->get($path)->assertNotFound();
    }

    public function test_a_suspended_household_shares_nothing(): void
    {
        [, $path] = $this->feed();
        $this->household->forceFill(['suspended_at' => now()])->save();

        $this->get($path)->assertNotFound();
    }

    public function test_the_database_holds_no_usable_secret(): void
    {
        [$feed, $path] = $this->feed();
        $token = basename($path);

        $this->assertNotSame($token, $feed->fresh()->token_hash);
        $this->assertSame(hash('sha256', $token), $feed->fresh()->token_hash);
    }

    public function test_the_admin_shows_the_address_once_on_creation(): void
    {
        $parent = User::factory()->create([
            'household_id' => $this->household->id,
            'person_id' => Person::factory()->create(['household_id' => $this->household->id])->id,
        ]);
        $this->actingAs($parent);

        Livewire::test(ManageIcsFeeds::class)
            ->callAction(TestAction::make('create'), ['name' => 'Oma', 'person_id' => $this->max->id])
            ->assertNotified(__('Copy the address now — it is not shown again.'));

        $this->assertNotNull(IcsFeed::query()->where('name', 'Oma')->value('token_hash'));
    }

    public function test_the_admin_page_leaves_with_the_area(): void
    {
        $this->assertTrue(IcsFeedResource::canAccess());
        $this->assertTrue(IcsFeedResource::shouldRegisterNavigation());

        $this->household->update(['opted_in_features' => []]);

        $this->assertFalse(IcsFeedResource::canAccess());
        $this->assertFalse(IcsFeedResource::shouldRegisterNavigation());
    }
}
