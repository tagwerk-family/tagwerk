<?php

declare(strict_types=1);

namespace App\Integrations\Calendar\Google;

use App\Integrations\Google\GoogleOAuth;
use Fahoba\PluginApi\Calendar\EventConflict;
use Fahoba\PluginApi\Calendar\SyncCursorExpired;
use Fahoba\PluginApi\Connections\ConnectedAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around the seven Calendar API endpoints Fahoba needs.
 *
 * Deliberately without google/apiclient: the official package pulls in a
 * large dependency tree and is hard to replace in tests, while the spec
 * requires checks against recorded fixtures.
 */
final class GoogleCalendarClient
{
    private const BASE = 'https://www.googleapis.com/calendar/v3';

    public function __construct(private readonly GoogleOAuth $oauth) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function calendarList(ConnectedAccount $source): array
    {
        $items = [];
        $pageToken = null;

        do {
            $query = ['maxResults' => 250, 'showHidden' => 'false'];

            if ($pageToken !== null) {
                $query['pageToken'] = $pageToken;
            }

            $page = $this->send($source, fn (PendingRequest $r): Response => $r->get(
                self::BASE.'/users/me/calendarList',
                $query,
            ));

            $items = [...$items, ...($page['items'] ?? [])];
            $pageToken = $page['nextPageToken'] ?? null;
        } while ($pageToken !== null);

        return $items;
    }

    /**
     * One page of events. Paging is up to the caller, because only it knows
     * what should happen to nextSyncToken.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function listEvents(ConnectedAccount $source, string $googleCalendarId, array $query): array
    {
        return $this->send($source, fn (PendingRequest $r): Response => $r->get(
            self::BASE.'/calendars/'.rawurlencode($googleCalendarId).'/events',
            $query,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function watch(
        ConnectedAccount $source,
        string $googleCalendarId,
        string $channelId,
        string $address,
        string $token,
    ): array {
        return $this->send($source, fn (PendingRequest $r): Response => $r->post(
            self::BASE.'/calendars/'.rawurlencode($googleCalendarId).'/events/watch',
            [
                'id' => $channelId,
                'type' => 'web_hook',
                'address' => $address,
                'token' => $token,
            ],
        ));
    }

    public function stopChannel(ConnectedAccount $source, string $channelId, string $resourceId): void
    {
        $this->send($source, fn (PendingRequest $r): Response => $r->post(
            self::BASE.'/channels/stop',
            ['id' => $channelId, 'resourceId' => $resourceId],
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function getEvent(ConnectedAccount $source, string $googleCalendarId, string $eventId): array
    {
        return $this->send($source, fn (PendingRequest $r): Response => $r->get(
            $this->eventUrl($googleCalendarId, $eventId),
        ));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function insertEvent(ConnectedAccount $source, string $googleCalendarId, array $body): array
    {
        return $this->send($source, fn (PendingRequest $r): Response => $r->post(
            self::BASE.'/calendars/'.rawurlencode($googleCalendarId).'/events',
            $body,
        ));
    }

    /**
     * Partial update with optimistic locking.
     *
     * Without `If-Match` a write would silently overwrite someone else's
     * change that arrived between loading and saving.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function patchEvent(
        ConnectedAccount $source,
        string $googleCalendarId,
        string $eventId,
        array $body,
        ?string $etag = null,
    ): array {
        return $this->send($source, fn (PendingRequest $r): Response => $this->withEtag($r, $etag)
            ->patch($this->eventUrl($googleCalendarId, $eventId), $body));
    }

    public function deleteEvent(
        ConnectedAccount $source,
        string $googleCalendarId,
        string $eventId,
        ?string $etag = null,
    ): void {
        $response = $this->request($source, fn (PendingRequest $r): Response => $this->withEtag($r, $etag)
            ->delete($this->eventUrl($googleCalendarId, $eventId)));

        /*
         * 404 and 410 mean here: the event is already gone there. The goal is
         * reached. That is why deleting bypasses send() — there 410 counts as
         * an expired syncToken, which would be wrong for this call.
         */
        if (in_array($response->status(), [404, 410], true)) {
            return;
        }

        $this->guardConflict($response);

        $response->throw();
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @return array<string, mixed>
     */
    private function send(ConnectedAccount $source, callable $call): array
    {
        $response = $this->request($source, $call);

        if ($response->status() === 410) {
            throw new SyncCursorExpired;
        }

        $this->guardConflict($response);

        $response->throw();

        return $response->json() ?? [];
    }

    /** @param  callable(PendingRequest): Response  $call */
    private function request(ConnectedAccount $source, callable $call): Response
    {
        $request = Http::withToken($this->oauth->freshAccessToken($source))
            ->acceptJson()
            ->timeout(20);

        $response = $call($request);

        /*
         * A 401 despite a freshly refreshed token means: the consent was
         * revoked. Another attempt would have the same result.
         */
        if ($response->status() === 401) {
            $this->oauth->markAuthorizationLost($source);
        }

        return $response;
    }

    private function guardConflict(Response $response): void
    {
        if ($response->status() === 412) {
            throw new EventConflict;
        }
    }

    private function withEtag(PendingRequest $request, ?string $etag): PendingRequest
    {
        return $etag === null ? $request : $request->withHeaders(['If-Match' => $etag]);
    }

    private function eventUrl(string $googleCalendarId, string $eventId): string
    {
        return self::BASE.'/calendars/'.rawurlencode($googleCalendarId).'/events/'.rawurlencode($eventId);
    }
}
