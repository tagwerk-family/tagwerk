<?php

declare(strict_types=1);

namespace Fahoba\CalDav;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeZone;
use Fahoba\PluginApi\Calendar\CalendarProvider;
use Fahoba\PluginApi\Calendar\ChangePage;
use Fahoba\PluginApi\Calendar\ConnectedCalendar;
use Fahoba\PluginApi\Calendar\EventDraft;
use Fahoba\PluginApi\Calendar\ListsCalendars;
use Fahoba\PluginApi\Calendar\RemoteCalendar;
use Fahoba\PluginApi\Calendar\RemoteEvent;
use Fahoba\PluginApi\Calendar\SyncsEvents;
use Fahoba\PluginApi\Calendar\WritesEvents;
use Fahoba\PluginApi\Connections\AuthorizationLost;
use Fahoba\PluginApi\Connections\ConnectedAccount;
use Fahoba\PluginApi\Connections\CredentialField;
use Fahoba\PluginApi\Connections\DescribesSetup;
use Fahoba\PluginApi\Connections\UsesCredentials;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;
use Throwable;

/**
 * Any CalDAV server: Nextcloud, iCloud, mailbox.org, Posteo, Radicale.
 *
 * CalDAV has no delta every server agrees on, so a sync reads the window
 * whole — but only once the calendar's change marker (CTag) has moved.
 * What the window no longer holds was deleted there. Series are expanded
 * here; a single date of a series is changed in the calendar app itself,
 * plain events are written both ways.
 */
final class CalDavCalendarProvider implements CalendarProvider, DescribesSetup, ListsCalendars, SyncsEvents, UsesCredentials, WritesEvents
{
    public const KEY = 'fahoba/caldav';

    public const PAST_MONTHS = 3;

    public const FUTURE_MONTHS = 12;

    /** Mirrors `EventProperties` of the Google provider, as iCalendar extensions. */
    private const REVISION = 'X-FAHOBA-REVISION';

    private const PEOPLE = 'X-FAHOBA-PEOPLE';

    /** Written before the rename to Fahoba; still read, dropped on our next write. */
    private const LEGACY = [
        self::REVISION => 'X-TAGWERK-REVISION',
        self::PEOPLE => 'X-TAGWERK-PEOPLE',
    ];

    public function __construct(private readonly CalDavClient $client) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'CalDAV';
    }

    public function icon(): string
    {
        return 'heroicon-o-calendar';
    }

    public function description(): string
    {
        return __('Brings in calendars from Nextcloud, iCloud, mailbox.org or any other CalDAV server and writes new events back.');
    }

    public function setupNotes(): array
    {
        return [
            __('The address is the one your provider names for calendar apps — the server, your calendar home or a single calendar.'),
            __('Use an app password where your provider offers one (iCloud requires it), not your main password.'),
            __('The server must be reachable from the internet; an address in your home network is refused.'),
            __('Repeating events come over as single dates. To change one date of a series, use your calendar app.'),
        ];
    }

    public function credentialFields(): array
    {
        return [
            new CredentialField(name: 'url', label: __('Address'), placeholder: 'https://cloud.example.org/remote.php/dav'),
            new CredentialField(name: 'username', label: __('User name')),
            new CredentialField(name: 'password', label: __('Password'), secret: true),
        ];
    }

    public function verifyCredentials(array $credentials): ?string
    {
        $login = Login::from($credentials);

        if (! str_starts_with(strtolower($login->url), 'https://') && ! str_starts_with(strtolower($login->url), 'http://')) {
            return __('The address starts with https://.');
        }

        try {
            return $this->client->calendars($login) === [] ? __('The server knows no calendar with events for this account.') : null;
        } catch (CalDavFailed $exception) {
            return match (true) {
                $exception->denied() => __('User name or password is wrong.'),
                $exception->status === 0 => __('Not reachable or not a public address.'),
                default => __('This does not look like a CalDAV address.'),
            };
        } catch (Throwable) {
            return __('This does not look like a CalDAV address.');
        }
    }

    public function accountLabel(array $credentials): string
    {
        $login = Login::from($credentials);

        return 'CalDAV – '.$login->username.'@'.parse_url($login->url, PHP_URL_HOST);
    }

    /** @return array<int, RemoteCalendar> */
    public function calendars(ConnectedAccount $source): array
    {
        return $this->guarded($source, fn (): array => array_map(
            static fn (array $calendar): RemoteCalendar => new RemoteCalendar($calendar['url'], $calendar['name'], $calendar['color']),
            $this->client->calendars(Login::of($source)),
        ));
    }

    public function changes(ConnectedCalendar $calendar, ?string $cursor, ?string $page): ChangePage
    {
        return $this->guarded($calendar->account(), function () use ($calendar, $cursor): ChangePage {
            $login = Login::of($calendar->account());
            $ctag = $this->client->ctag($login, $calendar->externalId());

            if ($cursor !== null && $ctag === $cursor) {
                return new ChangePage(nextCursor: $cursor);
            }

            $from = CarbonImmutable::now()->subMonths(self::PAST_MONTHS);
            $to = CarbonImmutable::now()->addMonths(self::FUTURE_MONTHS);
            $events = [];

            foreach ($this->client->events($login, $calendar->externalId(), $from, $to) as $object) {
                array_push($events, ...$this->instances($object, $calendar, $from, $to));
            }

            return new ChangePage(
                events: [...$events, ...$this->vanished($calendar, $events, $from, $to)],
                nextCursor: $ctag,
            );
        });
    }

    public function insert(ConnectedCalendar $calendar, EventDraft $draft): RemoteEvent
    {
        return $this->guarded($calendar->account(), function () use ($calendar, $draft): RemoteEvent {
            $uid = (string) Str::uuid();
            $url = rtrim($calendar->externalId(), '/').'/'.$uid.'.ics';
            $vcalendar = new VCalendar(['VEVENT' => ['UID' => $uid]]);
            $this->fill($vcalendar->VEVENT, $draft, $calendar);

            $etag = $this->client->put(Login::of($calendar->account()), $url, $vcalendar->serialize(), null);

            return $this->written((string) parse_url($url, PHP_URL_PATH), $draft, $etag ?? $this->etagOf($calendar, $url));
        });
    }

    public function update(ConnectedCalendar $calendar, string $externalId, EventDraft $draft, ?string $version): RemoteEvent
    {
        $this->refuseSeriesDate($externalId);

        return $this->guarded($calendar->account(), function () use ($calendar, $externalId, $draft, $version): RemoteEvent {
            $login = Login::of($calendar->account());
            $url = $this->client->resolve($calendar->externalId(), $externalId);
            $current = $this->client->get($login, $url);
            $vcalendar = $current !== null ? Reader::read($current['data']) : new VCalendar(['VEVENT' => ['UID' => (string) Str::uuid()]]);
            $this->fill($vcalendar->VEVENT, $draft, $calendar);

            $etag = $this->client->put($login, $url, $vcalendar->serialize(), $version ?? $current['etag'] ?? null);

            return $this->written($externalId, $draft, $etag ?? $this->etagOf($calendar, $url));
        });
    }

    public function delete(ConnectedCalendar $calendar, string $externalId, ?string $version): void
    {
        $this->refuseSeriesDate($externalId);

        $this->guarded($calendar->account(), fn () => $this->client->delete(
            Login::of($calendar->account()),
            $this->client->resolve($calendar->externalId(), $externalId),
        ));
    }

    public function fetch(ConnectedCalendar $calendar, string $externalId): RemoteEvent
    {
        $this->refuseSeriesDate($externalId);

        return $this->guarded($calendar->account(), function () use ($calendar, $externalId): RemoteEvent {
            $object = $this->client->get(Login::of($calendar->account()), $this->client->resolve($calendar->externalId(), $externalId));

            if ($object === null) {
                return RemoteEvent::cancelled($externalId);
            }

            $vevent = Reader::read($object['data'])->VEVENT;

            return $this->map($vevent, $externalId, $object['etag'], null, $calendar->zoneName());
        });
    }

    /**
     * Every date of one stored object in the window.
     *
     * @param  array{href: string, etag: ?string, data: string}  $object
     * @return list<RemoteEvent>
     */
    private function instances(array $object, ConnectedCalendar $calendar, CarbonImmutable $from, CarbonImmutable $to): array
    {
        try {
            $vcalendar = Reader::read($object['data'])->expand($from, $to, new DateTimeZone($calendar->zoneName()));
        } catch (Throwable) {
            // One broken object costs that object, not the calendar.
            return [];
        }

        $events = [];

        foreach ($vcalendar->select('VEVENT') as $vevent) {
            $recurrence = $vevent->{'RECURRENCE-ID'} ?? null;
            $id = $recurrence !== null ? $object['href'].'#'.$recurrence->getValue() : $object['href'];

            $events[] = $this->map($vevent, $id, $object['etag'], $recurrence !== null ? $object['href'] : null, $calendar->zoneName());
        }

        return $events;
    }

    /**
     * What this calendar holds from there inside the window but the window
     * no longer has: deleted at the source.
     *
     * @param  list<RemoteEvent>  $seen
     * @return list<RemoteEvent>
     */
    private function vanished(ConnectedCalendar $calendar, array $seen, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $seenIds = array_map(static fn (RemoteEvent $event): string => $event->externalId, $seen);

        return array_values(array_map(
            static fn (string $id): RemoteEvent => RemoteEvent::cancelled($id),
            array_diff($calendar->knownExternalIds($from, $to), $seenIds),
        ));
    }

    private function map(VEvent $vevent, string $id, ?string $etag, ?string $series, string $zone): RemoteEvent
    {
        $start = $vevent->DTSTART;
        $allDay = ! $start->hasTime();
        $startsAt = $allDay
            ? CarbonImmutable::parse($start->getValue(), $zone)->startOfDay()
            : CarbonImmutable::instance($start->getDateTime(new DateTimeZone($zone)));

        $endsAt = match (true) {
            isset($vevent->DTEND) && $allDay => CarbonImmutable::parse($vevent->DTEND->getValue(), $zone)->startOfDay(),
            isset($vevent->DTEND) => CarbonImmutable::instance($vevent->DTEND->getDateTime(new DateTimeZone($zone))),
            isset($vevent->DURATION) => $startsAt->add($vevent->DURATION->getDateInterval()),
            // RFC 5545: a date without an end is that one day.
            $allDay => $startsAt->addDay(),
            default => $startsAt->addHour(),
        };

        $people = $this->own($vevent, self::PEOPLE);

        return new RemoteEvent(
            externalId: $id,
            title: trim((string) ($vevent->SUMMARY ?? '')) !== '' ? (string) $vevent->SUMMARY : __('(no title)'),
            description: isset($vevent->DESCRIPTION) ? (string) $vevent->DESCRIPTION : null,
            location: isset($vevent->LOCATION) ? (string) $vevent->LOCATION : null,
            startsAt: $startsAt,
            endsAt: $endsAt,
            allDay: $allDay,
            version: $etag,
            updatedAt: isset($vevent->{'LAST-MODIFIED'}) ? CarbonImmutable::instance($vevent->{'LAST-MODIFIED'}->getDateTime()) : null,
            recurringEventId: $series,
            revisionMarker: $this->own($vevent, self::REVISION),
            personIds: $people === null ? null : array_values(array_filter(array_map(intval(...), explode(',', $people)))),
        );
    }

    private function own(VEvent $vevent, string $property): ?string
    {
        foreach ([$property, self::LEGACY[$property]] as $name) {
            if (isset($vevent->{$name})) {
                return (string) $vevent->{$name};
            }
        }

        return null;
    }

    private function fill(VEvent $vevent, EventDraft $draft, ConnectedCalendar $calendar): void
    {
        $zone = $calendar->zoneName();

        foreach (['DTSTART', 'DTEND', 'DURATION', 'SUMMARY', 'DESCRIPTION', 'LOCATION', self::REVISION, self::PEOPLE, ...self::LEGACY] as $property) {
            $vevent->remove($property);
        }

        if ($draft->allDay) {
            // The same arithmetic as when reading, the other way round.
            $vevent->add('DTSTART', $draft->startsAt->setTimezone($zone)->format('Ymd'), ['VALUE' => 'DATE']);
            $vevent->add('DTEND', $draft->endsAt->setTimezone($zone)->format('Ymd'), ['VALUE' => 'DATE']);
        } else {
            $vevent->add('DTSTART', $draft->startsAt->utc());
            $vevent->add('DTEND', $draft->endsAt->utc());
        }

        $vevent->add('SUMMARY', $draft->title);

        if ($draft->description !== null && $draft->description !== '') {
            $vevent->add('DESCRIPTION', $draft->description);
        }

        if ($draft->location !== null && $draft->location !== '') {
            $vevent->add('LOCATION', $draft->location);
        }

        $vevent->add(self::REVISION, $draft->revisionMarker);
        $vevent->add(self::PEOPLE, implode(',', $draft->personIds));
        $vevent->remove('LAST-MODIFIED');
        $vevent->add('LAST-MODIFIED', CarbonImmutable::now()->utc());
    }

    private function written(string $id, EventDraft $draft, ?string $etag): RemoteEvent
    {
        return new RemoteEvent(
            externalId: $id,
            title: $draft->title,
            description: $draft->description,
            location: $draft->location,
            startsAt: $draft->startsAt,
            endsAt: $draft->endsAt,
            allDay: $draft->allDay,
            version: $etag,
            revisionMarker: $draft->revisionMarker,
            personIds: $draft->personIds,
        );
    }

    /** Some servers answer a PUT without ETag; the next write needs one. */
    private function etagOf(ConnectedCalendar $calendar, string $url): ?string
    {
        return $this->client->get(Login::of($calendar->account()), $url)['etag'] ?? null;
    }

    private function refuseSeriesDate(string $externalId): void
    {
        if (str_contains($externalId, '#')) {
            throw ValidationException::withMessages([
                'calendar_id' => __('This is one date of a repeating event. Change it in your calendar app.'),
            ]);
        }
    }

    /**
     * A refused sign-in in the background means the password changed there:
     * only the household can fix that, so the connection says so.
     *
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     */
    private function guarded(ConnectedAccount $source, Closure $call): mixed
    {
        try {
            return $call();
        } catch (CalDavFailed $exception) {
            if (! $exception->denied()) {
                throw $exception;
            }

            $source->markInvalid();

            throw new AuthorizationLost($source);
        }
    }
}
