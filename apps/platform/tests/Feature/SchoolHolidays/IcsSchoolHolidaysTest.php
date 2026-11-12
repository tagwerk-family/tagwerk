<?php

declare(strict_types=1);

namespace Tests\Feature\SchoolHolidays;

use App\Enums\ProviderArea;
use App\Integrations\SchoolHolidays\Ics\IcsCalendar;
use App\Integrations\SchoolHolidays\Ics\IcsSchoolHolidays;
use App\Models\Household;
use App\Models\ProviderAccount;
use App\Support\HouseholdContext;
use App\Support\PublicAddressResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeResolver;
use Tests\TestCase;

/**
 * Holidays from an ICS calendar — the route for every country Fahoba has no
 * builtin rule for.
 */
final class IcsSchoolHolidaysTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A public address, so the check for internal targets lets it through.
        $this->app->instance(PublicAddressResolver::class, new FakeResolver(['ferien.example.test' => ['93.184.216.34']]));
    }

    private const URL = 'https://ferien.example.test/bayern.ics';

    private function ics(): string
    {
        // Folded line, escaped comma, exclusive DTEND — everything that
        // publishers actually send.
        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            'SUMMARY:Herbstferien Bayern',
            'DTSTART;VALUE=DATE:20261102',
            'DTEND;VALUE=DATE:20261107',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'SUMMARY:Weihnachtsferien\\, Bayern mit einem sehr langen Namen der gefal',
            ' tet wurde',
            'DTSTART;VALUE=DATE:20261224',
            'DTEND;VALUE=DATE:20270109',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'SUMMARY:Sommerferien 2026',
            'DTSTART;VALUE=DATE:20260803',
            'DTEND;VALUE=DATE:20260915',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'SUMMARY:Ohne Datum',
            'END:VEVENT',
            'END:VCALENDAR',
            '',
        ]);
    }

    private function account(): ProviderAccount
    {
        $household = Household::factory()->create();
        app(HouseholdContext::class)->setId($household->id);

        return ProviderAccount::create([
            'area' => ProviderArea::SchoolHolidays,
            'provider' => 'ics',
            'account_label' => 'bayern.ics',
            'credentials' => ['url' => self::URL],
        ]);
    }

    public function test_the_parser_reads_all_day_events_including_folding_and_escaping(): void
    {
        $events = (new IcsCalendar)->allDayEvents($this->ics());

        $this->assertCount(3, $events);
        $this->assertSame('Weihnachtsferien, Bayern mit einem sehr langen Namen der gefaltet wurde', $events[1]['title']);

        // DTEND is exclusive: the last day of the holidays is the day before.
        $this->assertSame('2026-11-06', $events[0]['end']->toDateString());
    }

    public function test_suggestions_are_limited_to_the_school_year(): void
    {
        Http::fake([self::URL => Http::response($this->ics())]);

        $suggestions = app(IcsSchoolHolidays::class)->forSchoolYear($this->account(), null, 2026);

        $this->assertSame(
            ['Herbstferien Bayern', 'Weihnachtsferien, Bayern mit einem sehr langen Namen der gefaltet wurde'],
            array_map(fn ($v) => $v->title, $suggestions),
        );
    }

    public function test_without_account_the_source_suggests_nothing(): void
    {
        $source = app(IcsSchoolHolidays::class);

        $this->assertFalse($source->isAvailable(null, 'DE-BY'));
        $this->assertSame([], $source->forSchoolYear(null, 'DE-BY', 2026));
    }

    public function test_an_unreachable_calendar_returns_no_suggestions_instead_of_an_error(): void
    {
        Http::fake([self::URL => Http::response('', 500)]);

        $this->assertSame([], app(IcsSchoolHolidays::class)->forSchoolYear($this->account(), null, 2026));
    }

    public function test_the_check_when_connecting_requires_a_readable_calendar(): void
    {
        Http::fake([
            self::URL => Http::response($this->ics()),
            'https://broken.example.test/*' => Http::response('<html>no</html>'),
        ]);

        $source = app(IcsSchoolHolidays::class);

        $this->assertNull($source->verifyCredentials(['url' => self::URL]));
        $this->assertNotNull($source->verifyCredentials(['url' => 'https://broken.example.test/x.ics']));
        $this->assertNotNull($source->verifyCredentials(['url' => 'file:///etc/passwd']));
    }
}
