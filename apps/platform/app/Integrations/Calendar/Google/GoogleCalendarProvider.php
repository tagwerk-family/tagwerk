<?php

declare(strict_types=1);

namespace App\Integrations\Calendar\Google;

use App\Integrations\Google\GoogleOAuth;
use App\Models\Calendar;
use Carbon\CarbonImmutable;
use Fahoba\PluginApi\Calendar\CalendarProvider;
use Fahoba\PluginApi\Calendar\ChangePage;
use Fahoba\PluginApi\Calendar\ConnectedCalendar;
use Fahoba\PluginApi\Calendar\EventDraft;
use Fahoba\PluginApi\Calendar\InvalidNotification;
use Fahoba\PluginApi\Calendar\ListsCalendars;
use Fahoba\PluginApi\Calendar\PushChannel;
use Fahoba\PluginApi\Calendar\RemoteCalendar;
use Fahoba\PluginApi\Calendar\RemoteEvent;
use Fahoba\PluginApi\Calendar\SyncsEvents;
use Fahoba\PluginApi\Calendar\WatchesChanges;
use Fahoba\PluginApi\Calendar\WritesEvents;
use Fahoba\PluginApi\Connections\ConnectedAccount;
use Fahoba\PluginApi\Connections\DescribesSetup;
use Fahoba\PluginApi\Connections\TokenSet;
use Fahoba\PluginApi\Connections\UsesOAuth;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Google Calendar as a provider.
 *
 * This is the one and only place that knows what Google's fields are called.
 * The client below speaks HTTP, the domain services above see nothing but
 * RemoteEvent and EventDraft — which makes Google replaceable without the
 * sync ever noticing.
 */
final class GoogleCalendarProvider implements CalendarProvider, DescribesSetup, ListsCalendars, SyncsEvents, UsesOAuth, WatchesChanges, WritesEvents
{
    private const PAGE_SIZE = 250;

    /** How far a full sync reaches into the past. */
    private const INITIAL_WINDOW_MONTHS = 3;

    public function __construct(
        private readonly GoogleCalendarClient $client,
        private readonly GoogleOAuth $oauth,
    ) {}

    public function key(): string
    {
        return 'google';
    }

    public function label(): string
    {
        return __('Google Calendar');
    }

    public function icon(): string
    {
        return 'heroicon-o-calendar-days';
    }

    public function description(): string
    {
        return __('Brings in events from Google Calendar and writes new ones back.');
    }

    public function setupNotes(): array
    {
        return [
            __('Google will ask which permissions Family Home Base gets — read and write calendars.'),
            __('You choose afterwards in the calendar settings which calendars appear.'),
            __('This approval only covers events. Tasks and lists stay untouched.'),
        ];
    }

    /** @return array<int, RemoteCalendar> */
    public function calendars(ConnectedAccount $source): array
    {
        $calendars = [];

        foreach ($this->client->calendarList($source) as $item) {
            $id = $item['id'] ?? null;

            if (! is_string($id) || $id === '') {
                continue;
            }

            $calendars[] = new RemoteCalendar(
                externalId: $id,
                title: (string) ($item['summary'] ?? $id),
                color: $item['backgroundColor'] ?? null,
                timezone: $item['timeZone'] ?? null,
                isPrimary: (bool) ($item['primary'] ?? false),
            );
        }

        return $calendars;
    }

    public function changes(ConnectedCalendar $calendar, ?string $cursor, ?string $page): ChangePage
    {
        $query = [
            'maxResults' => self::PAGE_SIZE,

            // A wall display needs expanded series instances.
            'singleEvents' => 'true',

            // Without showDeleted cancellations would stay invisible in the delta.
            'showDeleted' => 'true',
        ];

        if ($cursor !== null) {
            // Google rejects syncToken together with timeMin.
            $query['syncToken'] = $cursor;
        } else {
            $query['timeMin'] = now()->subMonths(self::INITIAL_WINDOW_MONTHS)->toRfc3339String();
        }

        if ($page !== null) {
            $query['pageToken'] = $page;
        }

        $response = $this->client->listEvents(
            $calendar->account(),
            $calendar->externalId(),
            $query,
        );

        $events = [];

        foreach ($response['items'] ?? [] as $item) {
            $event = $this->toRemoteEvent($item, $calendar);

            if ($event !== null) {
                $events[] = $event;
            }
        }

        return new ChangePage(
            events: $events,
            nextPage: $response['nextPageToken'] ?? null,

            // Google only returns nextSyncToken on the last page.
            nextCursor: $response['nextSyncToken'] ?? null,
        );
    }

    public function insert(ConnectedCalendar $calendar, EventDraft $draft): RemoteEvent
    {
        return $this->mustMap(
            $this->client->insertEvent(
                $calendar->account(),
                $calendar->externalId(),
                $this->body($calendar, $draft),
            ),
            $calendar,
        );
    }

    public function update(ConnectedCalendar $calendar, string $externalId, EventDraft $draft, ?string $version): RemoteEvent
    {
        return $this->mustMap(
            $this->client->patchEvent(
                $calendar->account(),
                $calendar->externalId(),
                $externalId,
                $this->body($calendar, $draft),
                $version,
            ),
            $calendar,
        );
    }

    public function delete(ConnectedCalendar $calendar, string $externalId, ?string $version): void
    {
        $this->client->deleteEvent(
            $calendar->account(),
            $calendar->externalId(),
            $externalId,
            $version,
        );
    }

    public function fetch(ConnectedCalendar $calendar, string $externalId): RemoteEvent
    {
        return $this->mustMap(
            $this->client->getEvent($calendar->account(), $calendar->externalId(), $externalId),
            $calendar,
        );
    }

    public function watch(ConnectedCalendar $calendar, string $channelId): PushChannel
    {
        $response = $this->client->watch(
            $calendar->account(),
            $calendar->externalId(),
            $channelId,
            (string) config('services.google.webhook_url'),
            (string) config('services.google.webhook_token'),
        );

        return new PushChannel(
            resourceId: $response['resourceId'] ?? null,
            expiresAt: isset($response['expiration'])
                ? CarbonImmutable::createFromTimestampMs((int) $response['expiration'])
                : null,
        );
    }

    public function unwatch(ConnectedAccount $source, string $channelId, string $resourceId): void
    {
        $this->client->stopChannel($source, $channelId, $resourceId);
    }

    public function channelFromNotification(Request $request): ?string
    {
        $expected = (string) config('services.google.webhook_token');

        if ($expected === '' || ! hash_equals($expected, (string) $request->header('X-Goog-Channel-Token'))) {
            throw new InvalidNotification;
        }

        /*
         * The confirmation when a channel is created carries no change — it is
         * genuine, but there is nothing to fetch.
         */
        if ($request->header('X-Goog-Resource-State') === 'sync') {
            return null;
        }

        $channel = (string) $request->header('X-Goog-Channel-ID');

        return $channel === '' ? null : $channel;
    }

    /**
     * Deliberately minimal. The full `calendar` scope would include deleting
     * whole calendars and would need a justification in a later verification.
     *
     * @return array<int, string>
     */
    public function scopes(): array
    {
        return [
            'https://www.googleapis.com/auth/calendar.events',
            'https://www.googleapis.com/auth/calendar.calendarlist.readonly',
            'https://www.googleapis.com/auth/userinfo.email',
        ];
    }

    public function authorizationUrl(string $state, string $redirectUri): string
    {
        return $this->oauth->authorizationUrl($state, $this->scopes(), $redirectUri);
    }

    public function exchangeCode(string $code, string $redirectUri): TokenSet
    {
        $tokens = $this->oauth->exchangeCode($code, $redirectUri);
        $accessToken = (string) ($tokens['access_token'] ?? '');

        return new TokenSet(
            accessToken: $accessToken,

            /*
             * Google only reliably returns the refresh token on the first
             * consent; null therefore means "keep the existing one", not
             * "there is none".
             */
            refreshToken: $tokens['refresh_token'] ?? null,
            expiresIn: (int) ($tokens['expires_in'] ?? 3600),
            accountLabel: $this->oauth->accountEmail($accessToken),
        );
    }

    public function freshAccessToken(ConnectedAccount $source): string
    {
        return $this->oauth->freshAccessToken($source);
    }

    public function markAuthorizationLost(ConnectedAccount $source): never
    {
        $this->oauth->markAuthorizationLost($source);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return RemoteEvent|null null when the item carries no ID
     */
    private function toRemoteEvent(array $item, ConnectedCalendar $calendar): ?RemoteEvent
    {
        $id = $item['id'] ?? null;

        if (! is_string($id) || $id === '') {
            return null;
        }

        if (($item['status'] ?? null) === 'cancelled') {
            return RemoteEvent::cancelled($id);
        }

        $times = $this->mapTimes($item, $calendar);

        return new RemoteEvent(
            externalId: $id,
            title: (string) ($item['summary'] ?? __('(no title)')),
            description: $item['description'] ?? null,
            location: $item['location'] ?? null,
            startsAt: $times['starts_at'],
            endsAt: $times['ends_at'],
            allDay: $times['all_day'],
            version: $item['etag'] ?? null,
            updatedAt: isset($item['updated']) ? CarbonImmutable::parse($item['updated']) : null,
            recurringEventId: $item['recurringEventId'] ?? null,
            revisionMarker: $this->privateProperty($item, EventProperties::REVISION),
            personIds: $this->personIds($item),
        );
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function mustMap(array $item, ConnectedCalendar $calendar): RemoteEvent
    {
        return $this->toRemoteEvent($item, $calendar)
            ?? throw new RuntimeException('Google returned an event without an id.');
    }

    /**
     * The mirrored person assignment.
     *
     * If the property is missing, null comes back — the sync then leaves an
     * existing assignment untouched, so an event created by hand in Google
     * does not wipe it.
     *
     * @param  array<string, mixed>  $item
     * @return array<int, int>|null
     */
    private function personIds(array $item): ?array
    {
        $raw = $this->privateProperty($item, EventProperties::PEOPLE);

        if ($raw === null) {
            return null;
        }

        return array_values(array_filter(array_map(intval(...), explode(',', $raw))));
    }

    /** @param  array<string, mixed>  $item */
    private function privateProperty(array $item, string $key): ?string
    {
        $value = data_get($item, 'extendedProperties.private.'.$key)
            ?? data_get($item, 'extendedProperties.private.'.(EventProperties::LEGACY[$key] ?? $key));

        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{all_day: bool, starts_at: CarbonImmutable, ends_at: CarbonImmutable}
     */
    private function mapTimes(array $item, ConnectedCalendar $calendar): array
    {
        $zone = Calendar::of($calendar)->timezone ?? config('app.timezone');
        $start = $item['start'] ?? [];
        $end = $item['end'] ?? [];

        if (isset($start['date'])) {
            $from = CarbonImmutable::parse($start['date'], $zone)->startOfDay();

            // Google's end date is exclusive for all-day events.
            $to = isset($end['date'])
                ? CarbonImmutable::parse($end['date'], $zone)->startOfDay()
                : $from->addDay();

            return ['all_day' => true, 'starts_at' => $from, 'ends_at' => $to];
        }

        $from = CarbonImmutable::parse($start['dateTime'] ?? 'now');

        return [
            'all_day' => false,
            'starts_at' => $from,
            'ends_at' => isset($end['dateTime'])
                ? CarbonImmutable::parse($end['dateTime'])
                : $from->addHour(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function body(ConnectedCalendar $calendar, EventDraft $draft): array
    {
        $zone = Calendar::of($calendar)->timezone ?? config('app.timezone');

        $times = $draft->allDay
            ? [
                // The same arithmetic as when reading, only the other way round.
                'start' => ['date' => $draft->startsAt->setTimezone($zone)->toDateString()],
                'end' => ['date' => $draft->endsAt->setTimezone($zone)->toDateString()],
            ]
            : [
                'start' => ['dateTime' => $draft->startsAt->toIso8601String(), 'timeZone' => $zone],
                'end' => ['dateTime' => $draft->endsAt->toIso8601String(), 'timeZone' => $zone],
            ];

        return [
            ...$times,
            'summary' => $draft->title,
            'description' => $draft->description,
            'location' => $draft->location,
            'extendedProperties' => [
                'private' => [
                    EventProperties::REVISION => $draft->revisionMarker,
                    EventProperties::PEOPLE => implode(',', $draft->personIds),
                ],
            ],
        ];
    }
}
