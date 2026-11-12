<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\Enums\ConnectionStatus;
use App\Models\Calendar;
use App\Models\Event;
use App\Models\Household;
use App\Models\Person;
use App\Models\ProviderAccount;
use App\Services\Calendar\CalendarImportService;
use App\Services\Calendar\CalendarSyncService;
use App\Services\Calendar\EventWriteService;
use App\Support\HouseholdContext;
use App\Support\PublicAddressResolver;
use Carbon\CarbonImmutable;
use Fahoba\CalDav\CalDavCalendarProvider;
use Fahoba\PluginApi\Calendar\EventConflict;
use Fahoba\PluginApi\Connections\AuthorizationLost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Sabre\VObject\Reader;
use Tests\Support\FakeResolver;
use Tests\TestCase;

/**
 * A CalDAV server in both directions: found from whatever address people
 * copy, read whole when it changed, series as single dates, plain events
 * written back with their ETag.
 */
final class CalDavTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://dav.example.test';

    private const HOME = '/dav/calendars/anna/';

    private const FAMILY = '/dav/calendars/anna/family/';

    private Household $household;

    private ProviderAccount $account;

    /** @var array<string, array{etag: string, data: string}> path → object */
    private array $objects = [];

    private int $ctag = 1;

    /** @var list<string> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00', 'Europe/Vienna'));
        $this->app->instance(PublicAddressResolver::class, new FakeResolver([
            'dav.example.test' => ['93.184.216.34'],
            'nas.example.test' => ['192.168.1.5'],
        ]));

        $this->household = Household::factory()->create(['timezone' => 'Europe/Vienna']);
        app(HouseholdContext::class)->setId((int) $this->household->id);
        $this->account = ProviderAccount::factory()->create([
            'household_id' => $this->household->id,
            'provider' => CalDavCalendarProvider::KEY,
            'access_token' => null,
            'refresh_token' => null,
            'credentials' => ['url' => self::BASE.'/dav/', 'username' => 'anna', 'password' => 'app-password'],
        ]);

        Http::fake(fn (Request $request) => $this->server($request));
    }

    public function test_calendars_are_found_from_the_server_address(): void
    {
        app(CalendarImportService::class)->import($this->account);

        $calendar = $this->calendar();
        $this->assertSame(self::BASE.self::FAMILY, $calendar->external_id);
        $this->assertSame('Familie', $calendar->summary);
        $this->assertSame('#3366ff', $calendar->color);
        // The task list on the same server is no calendar for events.
        $this->assertSame(1, Calendar::query()->where('provider_account_id', $this->account->id)->count());
    }

    public function test_events_come_over_on_the_household_day(): void
    {
        $this->object('turnen', "DTSTART;TZID=Europe/Vienna:20261022T160000\r\nDTEND;TZID=Europe/Vienna:20261022T170000\r\nSUMMARY:Turnen");
        $this->object('ausflug', "DTSTART;VALUE=DATE:20261024\r\nDTEND;VALUE=DATE:20261025\r\nSUMMARY:Ausflug");

        $this->sync();

        $turnen = Event::query()->where('title', 'Turnen')->sole();
        $this->assertTrue(CarbonImmutable::parse('2026-10-22 14:00', 'UTC')->equalTo($turnen->starts_at));
        $this->assertSame(self::FAMILY.'turnen.ics', $turnen->external_id);

        $ausflug = Event::query()->where('title', 'Ausflug')->sole();
        $this->assertTrue($ausflug->all_day);
        $this->assertSame('2026-10-24 00:00', $ausflug->starts_at->setTimezone('Europe/Vienna')->format('Y-m-d H:i'));
        $this->assertSame('2026-10-25 00:00', $ausflug->ends_at->setTimezone('Europe/Vienna')->format('Y-m-d H:i'));
    }

    public function test_a_series_becomes_its_dates(): void
    {
        $this->object('chor', "DTSTART;TZID=Europe/Vienna:20261021T190000\r\nDURATION:PT90M\r\nRRULE:FREQ=WEEKLY;COUNT=3\r\nSUMMARY:Chor");

        $this->sync();

        $dates = Event::query()->where('title', 'Chor')->orderBy('starts_at')->get();
        $this->assertCount(3, $dates);
        $this->assertSame('19:00', $dates[2]->starts_at->setTimezone('Europe/Vienna')->format('H:i'));
        $this->assertSame('20:30', $dates[2]->ends_at->setTimezone('Europe/Vienna')->format('H:i'));
        $this->assertSame(self::FAMILY.'chor.ics', $dates[0]->recurring_event_id);
        $this->assertStringStartsWith(self::FAMILY.'chor.ics#', $dates[0]->external_id);
    }

    public function test_an_unchanged_calendar_is_not_read_again(): void
    {
        $this->object('turnen', "DTSTART;TZID=Europe/Vienna:20261022T160000\r\nDTEND;TZID=Europe/Vienna:20261022T170000\r\nSUMMARY:Turnen");
        $this->sync();
        $this->requests = [];

        app(CalendarSyncService::class)->sync($this->calendar()->fresh());

        $this->assertSame(['PROPFIND'], $this->requests);
    }

    public function test_deleted_there_is_deleted_here(): void
    {
        $this->object('turnen', "DTSTART;TZID=Europe/Vienna:20261022T160000\r\nDTEND;TZID=Europe/Vienna:20261022T170000\r\nSUMMARY:Turnen");
        $this->sync();

        unset($this->objects[self::FAMILY.'turnen.ics']);
        $this->ctag++;
        app(CalendarSyncService::class)->sync($this->calendar()->fresh());

        $this->assertSame(0, Event::query()->count());
    }

    public function test_a_new_event_is_written_there_and_not_echoed(): void
    {
        $this->sync();
        $lena = Person::factory()->child()->create(['household_id' => $this->household->id]);

        $event = app(EventWriteService::class)->create($this->calendar(), [
            'title' => 'Zahnarzt',
            'starts_at' => CarbonImmutable::parse('2026-10-23 09:00', 'Europe/Vienna'),
            'ends_at' => CarbonImmutable::parse('2026-10-23 10:00', 'Europe/Vienna'),
            'all_day' => false,
        ], [$lena->id]);

        $stored = $this->objects[$event->external_id];
        $vevent = Reader::read($stored['data'])->VEVENT;
        $this->assertSame('Zahnarzt', (string) $vevent->SUMMARY);
        $this->assertSame('20261023T070000Z', (string) $vevent->DTSTART);
        $this->assertSame((string) $lena->id, (string) $vevent->{'X-FAHOBA-PEOPLE'});
        $this->assertSame($stored['etag'], $event->external_version);

        $result = app(CalendarSyncService::class)->sync($this->calendar()->fresh());
        $this->assertSame(1, $result->skipped);
        $this->assertSame(1, Event::query()->count());
    }

    public function test_a_change_there_in_between_is_a_conflict(): void
    {
        $this->object('turnen', "DTSTART;TZID=Europe/Vienna:20261022T160000\r\nDTEND;TZID=Europe/Vienna:20261022T170000\r\nSUMMARY:Turnen");
        $this->sync();
        $event = Event::query()->sole();
        $this->objects[self::FAMILY.'turnen.ics']['etag'] = '"changed-there"';

        $this->expectException(EventConflict::class);

        app(EventWriteService::class)->update($event, ['title' => 'Turnen fällt aus']);
    }

    public function test_one_date_of_a_series_is_changed_in_the_calendar_app(): void
    {
        $this->object('chor', "DTSTART;TZID=Europe/Vienna:20261021T190000\r\nDURATION:PT90M\r\nRRULE:FREQ=WEEKLY;COUNT=3\r\nSUMMARY:Chor");
        $this->sync();

        $this->expectException(ValidationException::class);

        app(EventWriteService::class)->update(Event::query()->orderBy('starts_at')->first(), ['title' => 'Kein Chor']);
    }

    public function test_a_refused_password_marks_the_connection(): void
    {
        $this->sync();
        $this->account->update(['credentials' => [...$this->account->credentials, 'password' => 'old']]);

        try {
            app(CalendarSyncService::class)->sync($this->calendar()->fresh());
            $this->fail('The refused sign-in must surface.');
        } catch (AuthorizationLost) {
            // The job reports it; what matters is the connection's state.
        }

        $this->assertSame(ConnectionStatus::InvalidGrant, $this->account->fresh()->status);
    }

    public function test_the_wizard_checks_the_sign_in(): void
    {
        $provider = app(CalDavCalendarProvider::class);

        $this->assertNull($provider->verifyCredentials(['url' => self::BASE.'/dav/', 'username' => 'anna', 'password' => 'app-password']));
        $this->assertSame(__('User name or password is wrong.'), $provider->verifyCredentials(['url' => self::BASE.'/dav/', 'username' => 'anna', 'password' => 'nope']));
    }

    public function test_an_address_in_a_home_network_is_refused(): void
    {
        $this->assertSame(
            __('Not reachable or not a public address.'),
            app(CalDavCalendarProvider::class)->verifyCredentials(['url' => 'https://nas.example.test/dav/', 'username' => 'anna', 'password' => 'app-password']),
        );
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'nas.example.test'));
    }

    private function calendar(): Calendar
    {
        return Calendar::query()->where('provider_account_id', $this->account->id)->sole();
    }

    private function sync(): void
    {
        app(CalendarImportService::class)->import($this->account);
        app(CalendarSyncService::class)->sync($this->calendar());
    }

    private function object(string $name, string $properties): void
    {
        $this->objects[self::FAMILY.$name.'.ics'] = [
            'etag' => '"'.$name.'-1"',
            'data' => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//EN\r\nBEGIN:VEVENT\r\nUID:{$name}\r\n{$properties}\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
        ];
        $this->ctag++;
    }

    /** A small CalDAV server: discovery, one calendar plus a task list, objects in memory. */
    private function server(Request $request): mixed
    {
        $this->requests[] = $request->method();
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        if ($request->header('Authorization')[0] !== 'Basic '.base64_encode('anna:app-password')) {
            return Http::response('', 401);
        }

        return match (true) {
            $request->method() === 'PROPFIND' && $path === '/dav/' => $this->multistatus('<d:response><d:href>/dav/</d:href><d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype><d:current-user-principal><d:href>/dav/principals/anna/</d:href></d:current-user-principal></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>'),
            $request->method() === 'PROPFIND' && $path === '/dav/principals/anna/' => $this->multistatus('<d:response><d:href>/dav/principals/anna/</d:href><d:propstat><d:prop><c:calendar-home-set><d:href>'.self::HOME.'</d:href></c:calendar-home-set></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>'),
            $request->method() === 'PROPFIND' && $path === self::HOME => $this->multistatus(
                '<d:response><d:href>'.self::HOME.'</d:href><d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>'
                .'<d:response><d:href>'.self::FAMILY.'</d:href><d:propstat><d:prop><d:resourcetype><d:collection/><c:calendar/></d:resourcetype><d:displayname>Familie</d:displayname><a:calendar-color>#3366FFFF</a:calendar-color><c:supported-calendar-component-set><c:comp name="VEVENT"/></c:supported-calendar-component-set></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>'
                .'<d:response><d:href>/dav/calendars/anna/todo/</d:href><d:propstat><d:prop><d:resourcetype><d:collection/><c:calendar/></d:resourcetype><d:displayname>Erledigen</d:displayname><c:supported-calendar-component-set><c:comp name="VTODO"/></c:supported-calendar-component-set></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>'
            ),
            $request->method() === 'PROPFIND' && $path === self::FAMILY => $this->multistatus('<d:response><d:href>'.self::FAMILY.'</d:href><d:propstat><d:prop><cs:getctag>ctag-'.$this->ctag.'</cs:getctag></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>'),
            $request->method() === 'REPORT' && $path === self::FAMILY => $this->multistatus(implode('', array_map(
                static fn (string $href, array $object): string => '<d:response><d:href>'.$href.'</d:href><d:propstat><d:prop><d:getetag>'.htmlspecialchars($object['etag']).'</d:getetag><c:calendar-data>'.htmlspecialchars($object['data']).'</c:calendar-data></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>',
                array_keys($this->objects),
                $this->objects,
            ))),
            $request->method() === 'GET' => isset($this->objects[$path])
                ? Http::response($this->objects[$path]['data'], 200, ['ETag' => $this->objects[$path]['etag']])
                : Http::response('', 404),
            $request->method() === 'PUT' => $this->store($request, $path),
            $request->method() === 'DELETE' => $this->remove($path),
            default => Http::response('', 405),
        };
    }

    private function store(Request $request, string $path): mixed
    {
        $match = $request->header('If-Match')[0] ?? null;
        $exists = isset($this->objects[$path]);

        if (($match !== null && (! $exists || $this->objects[$path]['etag'] !== $match)) || ($match === null && $exists)) {
            return Http::response('', 412);
        }

        $etag = '"'.md5($request->body()).'"';
        $this->objects[$path] = ['etag' => $etag, 'data' => $request->body()];
        $this->ctag++;

        return Http::response('', $exists ? 204 : 201, ['ETag' => $etag]);
    }

    private function remove(string $path): mixed
    {
        unset($this->objects[$path]);
        $this->ctag++;

        return Http::response('', 204);
    }

    private function multistatus(string $responses): mixed
    {
        return Http::response('<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:cs="http://calendarserver.org/ns/" xmlns:a="http://apple.com/ns/ical/">'.$responses.'</d:multistatus>', 207);
    }
}
