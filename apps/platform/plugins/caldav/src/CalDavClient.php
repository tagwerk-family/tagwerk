<?php

declare(strict_types=1);

namespace Fahoba\CalDav;

use App\Support\OutboundHttp;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Fahoba\PluginApi\Calendar\EventConflict;
use Illuminate\Http\Client\Response;

/**
 * The few WebDAV/CalDAV requests Fahoba needs, through `OutboundHttp`:
 * the address comes from a household and must not point into the server's
 * own network.
 */
final class CalDavClient
{
    private const REDIRECTS = 3;

    private const DAV = 'DAV:';

    private const CALDAV = 'urn:ietf:params:xml:ns:caldav';

    private const CALSERVER = 'http://calendarserver.org/ns/';

    private const APPLE = 'http://apple.com/ns/ical/';

    public function __construct(private readonly OutboundHttp $http) {}

    /**
     * The account's calendars. The address may be the server, the principal,
     * the calendar home or one calendar — people copy whatever their
     * provider's help page shows.
     *
     * @return list<array{url: string, name: string, color: ?string}>
     */
    public function calendars(Login $login): array
    {
        $start = $this->propfind($login, $login->url, 0, '<d:resourcetype/><d:displayname/><d:current-user-principal/><c:calendar-home-set/><a:calendar-color/>');
        $props = $start[0]['props'] ?? [];

        if ($this->isCalendar($props)) {
            return [$this->calendar(['href' => $login->url, 'props' => $props])];
        }

        $home = $this->href($props['calendar-home-set'] ?? null);

        if ($home === null && ($principal = $this->href($props['current-user-principal'] ?? null)) !== null) {
            $found = $this->propfind($login, $this->resolve($login->url, $principal), 0, '<c:calendar-home-set/>');
            $home = $this->href($found[0]['props']['calendar-home-set'] ?? null);
        }

        $home = $home !== null ? $this->resolve($login->url, $home) : $login->url;
        $calendars = [];

        foreach ($this->propfind($login, $home, 1, '<d:resourcetype/><d:displayname/><a:calendar-color/><c:supported-calendar-component-set/>') as $entry) {
            if ($this->isCalendar($entry['props']) && $this->carriesEvents($entry['props'])) {
                $calendars[] = $this->calendar(['href' => $this->resolve($home, $entry['href']), 'props' => $entry['props']]);
            }
        }

        return $calendars;
    }

    /** The calendar's change marker; it changes with every write there. Null if the server keeps none. */
    public function ctag(Login $login, string $calendar): ?string
    {
        $props = $this->propfind($login, $calendar, 0, '<cs:getctag/><d:sync-token/>')[0]['props'] ?? [];
        $value = trim(($props['getctag'] ?? $props['sync-token'] ?? null)?->textContent ?? '');

        return $value !== '' ? $value : null;
    }

    /**
     * Every object with an event in the window — series whole, not expanded:
     * expanding on the server is optional in the protocol and missing in
     * some servers.
     *
     * @return list<array{href: string, etag: ?string, data: string}>
     */
    public function events(Login $login, string $calendar, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $range = sprintf('start="%s" end="%s"', $from->utc()->format('Ymd\THis\Z'), $to->utc()->format('Ymd\THis\Z'));
        $body = <<<XML
            <?xml version="1.0" encoding="utf-8"?>
            <c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
              <d:prop><d:getetag/><c:calendar-data/></d:prop>
              <c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT"><c:time-range {$range}/></c:comp-filter></c:comp-filter></c:filter>
            </c:calendar-query>
            XML;

        $objects = [];

        foreach ($this->multistatus($this->send($login, 'REPORT', $calendar, $body, ['Depth' => '1'])) as $entry) {
            $data = $entry['props']['calendar-data'] ?? null;

            if ($data !== null) {
                $objects[] = ['href' => $entry['href'], 'etag' => $this->etag($entry['props']['getetag'] ?? null), 'data' => $data->textContent];
            }
        }

        return $objects;
    }

    /** @return array{etag: ?string, data: string}|null null when the object is gone */
    public function get(Login $login, string $url): ?array
    {
        $response = $this->send($login, 'GET', $url);

        if ($response->status() === 404 || $response->status() === 410) {
            return null;
        }

        return ['etag' => $response->header('ETag') ?: null, 'data' => $response->body()];
    }

    /**
     * Writes one object; `$version` guards against overwriting a change
     * made there in the meantime, null creates and refuses an existing one.
     *
     * @return string|null the new ETag, if the server says it
     *
     * @throws EventConflict
     */
    public function put(Login $login, string $url, string $ics, ?string $version): ?string
    {
        $response = $this->send($login, 'PUT', $url, $ics, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            ...($version !== null ? ['If-Match' => $version] : ['If-None-Match' => '*']),
        ]);

        if ($response->status() === 412) {
            throw new EventConflict;
        }

        $this->ensureSuccess($response);

        return $response->header('ETag') ?: null;
    }

    /** Gone already counts as deleted. */
    public function delete(Login $login, string $url): void
    {
        $response = $this->send($login, 'DELETE', $url);

        if (! in_array($response->status(), [404, 410], true)) {
            $this->ensureSuccess($response);
        }
    }

    /** An href (usually an absolute path) against the address it came from. */
    public function resolve(string $base, string $href): string
    {
        if (preg_match('#^https?://#i', $href) === 1) {
            return $href;
        }

        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($href, '/')) {
            return $origin.$href;
        }

        return $origin.rtrim(dirname(($parts['path'] ?? '/').'x'), '/').'/'.$href;
    }

    /** @return list<array{href: string, props: array<string, DOMElement>}> */
    private function propfind(Login $login, string $url, int $depth, string $props): array
    {
        $body = '<?xml version="1.0" encoding="utf-8"?><d:propfind xmlns:d="DAV:" xmlns:c="'.self::CALDAV.'" xmlns:cs="'.self::CALSERVER.'" xmlns:a="'.self::APPLE.'"><d:prop>'.$props.'</d:prop></d:propfind>';

        return $this->multistatus($this->send($login, 'PROPFIND', $url, $body, ['Depth' => (string) $depth]));
    }

    /**
     * @param  array<string, string>  $headers
     *
     * @throws CalDavFailed
     */
    private function send(Login $login, string $method, string $url, string $body = '', array $headers = []): Response
    {
        for ($hop = 0; $hop <= self::REDIRECTS; $hop++) {
            $response = $this->http->send($method, $url, [
                'auth' => [$login->username, $login->password],
                'headers' => $method === 'GET' || $method === 'DELETE' ? $headers : ['Content-Type' => 'application/xml; charset=utf-8', ...$headers],
                'body' => $body,
            ], timeout: 15);

            if ($response === null) {
                throw new CalDavFailed(0, 'CalDAV server not reachable or not a public address.');
            }

            if ($response->status() === 401 || $response->status() === 403) {
                throw new CalDavFailed($response->status());
            }

            $location = $response->header('Location');

            if (! in_array($response->status(), [301, 302, 307, 308], true) || $location === '') {
                return $response;
            }

            // Each hop through OutboundHttp again: a redirect must not lead inside.
            $url = $this->resolve($url, $location);
        }

        throw new CalDavFailed(0, 'Too many redirects.');
    }

    private function ensureSuccess(Response $response): void
    {
        if (! $response->successful()) {
            throw new CalDavFailed($response->status());
        }
    }

    /** @return list<array{href: string, props: array<string, DOMElement>}> */
    private function multistatus(Response $response): array
    {
        if ($response->status() !== 207) {
            throw new CalDavFailed($response->status(), "Expected a multistatus answer, got {$response->status()}.");
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($response->body(), LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new CalDavFailed(207, 'The server answered with broken XML.');
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('d', self::DAV);
        $entries = [];

        foreach ($xpath->query('//d:response') ?: [] as $node) {
            $href = trim($xpath->evaluate('string(d:href)', $node));
            $props = [];

            foreach ($xpath->query('d:propstat[contains(d:status, " 200 ")]/d:prop/*', $node) ?: [] as $prop) {
                if ($prop instanceof DOMElement) {
                    $props[$prop->localName] = $prop;
                }
            }

            $entries[] = ['href' => $href, 'props' => $props];
        }

        return $entries;
    }

    /** @param array<string, DOMElement> $props */
    private function isCalendar(array $props): bool
    {
        return isset($props['resourcetype']) && $props['resourcetype']->getElementsByTagNameNS(self::CALDAV, 'calendar')->length > 0;
    }

    /**
     * Task-only lists (VTODO) are calendars too; Fahoba wants the ones with events.
     *
     * @param  array<string, DOMElement>  $props
     */
    private function carriesEvents(array $props): bool
    {
        $set = $props['supported-calendar-component-set'] ?? null;

        if ($set === null) {
            return true;
        }

        foreach ($set->getElementsByTagNameNS(self::CALDAV, 'comp') as $component) {
            if ($component instanceof DOMElement && strtoupper($component->getAttribute('name')) === 'VEVENT') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{href: string, props: array<string, DOMElement>}  $entry
     * @return array{url: string, name: string, color: ?string}
     */
    private function calendar(array $entry): array
    {
        $name = trim($entry['props']['displayname']->textContent ?? '');
        $color = trim($entry['props']['calendar-color']->textContent ?? '');

        return [
            'url' => $entry['href'],
            'name' => $name !== '' ? $name : basename(rtrim($entry['href'], '/')),
            // Apple writes #RRGGBBAA; the household's colours are #RRGGBB.
            'color' => preg_match('/^#[0-9a-f]{6}/i', $color) === 1 ? strtolower(substr($color, 0, 7)) : null,
        ];
    }

    private function href(?DOMElement $prop): ?string
    {
        $href = $prop?->getElementsByTagNameNS(self::DAV, 'href')->item(0)?->textContent;

        return $href !== null && trim($href) !== '' ? trim($href) : null;
    }

    private function etag(?DOMElement $prop): ?string
    {
        $etag = trim($prop->textContent ?? '');

        return $etag !== '' ? $etag : null;
    }
}
