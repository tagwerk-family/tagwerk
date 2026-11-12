<?php

declare(strict_types=1);

namespace App\Integrations\SchoolHolidays\Ics;

use App\Support\OutboundHttp;
use Carbon\CarbonImmutable;
use Fahoba\PluginApi\Connections\ConnectedAccount;
use Fahoba\PluginApi\Connections\CredentialField;
use Fahoba\PluginApi\Connections\DescribesSetup;
use Fahoba\PluginApi\Connections\UsesCredentials;
use Fahoba\PluginApi\SchoolHolidays\SchoolHolidaySource;
use Fahoba\PluginApi\SchoolHolidays\SchoolHolidaySuggestion;
use Illuminate\Support\Facades\Cache;

/**
 * Holidays from an ICS calendar published by a school, a country or a
 * holiday portal.
 *
 * The route for every country without a built-in rule. It is only read when
 * someone looks at suggestions, and then served from the cache for a day.
 */
final class IcsSchoolHolidays implements DescribesSetup, SchoolHolidaySource, UsesCredentials
{
    /** A holiday calendar is a few kilobytes; anything above that is not a holiday calendar. */
    private const MAX_BYTES = 2_000_000;

    private const TTL = 86_400;

    public function __construct(
        private readonly IcsCalendar $parser,
        private readonly OutboundHttp $http,
    ) {}

    public function key(): string
    {
        return 'ics';
    }

    public function label(): string
    {
        return 'Ferienkalender (ICS)';
    }

    public function icon(): string
    {
        return 'heroicon-o-calendar';
    }

    public function description(): string
    {
        return __('Holidays from a published calendar — for any country and any school.');
    }

    public function setupNotes(): array
    {
        return [
            __('Many countries, schools and holiday portals publish their holidays as an ICS file or calendar subscription.'),
            __('Enter the address of this file here. It is only read when holidays are suggested.'),
        ];
    }

    public function credentialFields(): array
    {
        return [
            new CredentialField(
                name: 'url',
                label: __('Calendar address'),
                placeholder: 'https://…/holidays.ics',
            ),
        ];
    }

    public function verifyCredentials(array $credentials): ?string
    {
        $url = (string) ($credentials['url'] ?? '');

        if (! $this->isAllowed($url)) {
            return __('The address must start with http:// or https:// and point to the public internet.');
        }

        $text = $this->fetch($url);

        if ($text === null) {
            return __('The calendar cannot be reached at this address.');
        }

        return $this->parser->allDayEvents($text) === []
            ? __('There is no calendar with all-day events at this address.')
            : null;
    }

    public function accountLabel(array $credentials): string
    {
        $path = (string) parse_url((string) ($credentials['url'] ?? ''), PHP_URL_PATH);

        return basename($path) !== '' ? basename($path) : __('Holiday calendar');
    }

    public function isAvailable(?ConnectedAccount $account, ?string $region): bool
    {
        return $this->url($account) !== null;
    }

    public function forSchoolYear(?ConnectedAccount $account, ?string $region, int $startYear): array
    {
        $url = $this->url($account);

        if ($url === null) {
            return [];
        }

        $text = Cache::remember(
            'school-holidays:ics:'.sha1($url),
            self::TTL,
            fn (): string => $this->fetch($url) ?? '',
        );

        // A school year runs from September to August.
        $from = CarbonImmutable::create($startYear, 9, 1);
        $to = CarbonImmutable::create($startYear + 1, 8, 31);

        $suggestions = [];

        foreach ($this->parser->allDayEvents($text) as $event) {
            if ($event['start']->lessThan($from) || $event['start']->greaterThan($to)) {
                continue;
            }

            $suggestions[] = new SchoolHolidaySuggestion($event['title'], $event['start'], $event['end']);
        }

        usort(
            $suggestions,
            static fn (SchoolHolidaySuggestion $a, SchoolHolidaySuggestion $b): int => $a->startsOn <=> $b->startsOn,
        );

        return $suggestions;
    }

    private function url(?ConnectedAccount $account): ?string
    {
        $url = $account?->credentials['url'] ?? null;

        return is_string($url) && $this->isAllowed($url) ? $url : null;
    }

    /**
     * Public network addresses only: `file://` would be a read path onto the
     * server, an internal host a way into its private network.
     */
    private function isAllowed(string $url): bool
    {
        return $this->http->isPublic($url);
    }

    private function fetch(string $url): ?string
    {
        $response = $this->http->get($url);

        if ($response === null) {
            return null;
        }

        $text = $response->body();

        return $response->successful() && strlen($text) <= self::MAX_BYTES && str_contains($text, 'BEGIN:VCALENDAR')
            ? $text
            : null;
    }
}
