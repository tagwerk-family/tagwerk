<?php

declare(strict_types=1);

namespace Fahoba\IcsFeed;

use App\Models\Event;
use App\Models\Household;
use App\Models\SchoolClosure;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Sabre\VObject\Component\VCalendar;

/**
 * The feed's calendar as iCalendar text. Runs inside the household's context
 * (the controller sets it), like every read of family data.
 *
 * A month back, a year ahead: enough for a phone's calendar, small enough
 * that the subscription stays quick for apps that refetch every hour.
 */
final class IcsCalendar
{
    private const MONTHS_BACK = 1;

    private const MONTHS_AHEAD = 12;

    public function build(IcsFeed $feed, Household $household): string
    {
        $zone = (string) ($household->timezone ?? config('app.timezone'));
        $now = CarbonImmutable::now($zone);
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        $calendar = new VCalendar([
            'PRODID' => '-//Fahoba//ICS feed//EN',
            'X-WR-CALNAME' => $feed->name,
            'X-WR-TIMEZONE' => $zone,
        ]);

        $events = Event::query()
            ->whereHas('calendar', fn (Builder $query) => $query->where('is_active', true))
            ->where('ends_at', '>=', $now->subMonths(self::MONTHS_BACK))
            ->where('starts_at', '<=', $now->addMonths(self::MONTHS_AHEAD))
            ->with(['people', 'calendar.people'])
            ->orderBy('starts_at')
            ->get();

        foreach ($events as $event) {
            if ($feed->person_id !== null && ! $event->resolvedPeople()->contains('id', $feed->person_id)) {
                continue;
            }

            $properties = [
                'UID' => "event-{$event->id}@{$host}",
                'SUMMARY' => $event->title,
                'DTSTAMP' => ($event->updated_at ?? $now)->setTimezone('UTC'),
            ];

            if ($event->description !== null && $event->description !== '') {
                $properties['DESCRIPTION'] = $event->description;
            }

            if ($event->location !== null && $event->location !== '') {
                $properties['LOCATION'] = $event->location;
            }

            $vevent = $calendar->add('VEVENT', $properties);
            $this->times($vevent, $event->starts_at, $event->ends_at, $event->all_day, $zone);
        }

        if ($feed->include_closures) {
            $this->closures($calendar, $feed, $now, $host);
        }

        return $calendar->serialize();
    }

    private function times(mixed $vevent, ?CarbonImmutable $from, ?CarbonImmutable $to, bool $allDay, string $zone): void
    {
        $from ??= CarbonImmutable::now($zone);
        $to ??= $from->addHour();

        if ($allDay) {
            // Plain dates in the household's zone: midnight UTC would slip a day east of Greenwich.
            $vevent->add('DTSTART', $from->setTimezone($zone)->format('Ymd'), ['VALUE' => 'DATE']);
            $vevent->add('DTEND', $to->setTimezone($zone)->format('Ymd'), ['VALUE' => 'DATE']);

            return;
        }

        $vevent->add('DTSTART', $from->setTimezone(new DateTimeZone('UTC')));
        $vevent->add('DTEND', $to->setTimezone(new DateTimeZone('UTC')));
    }

    private function closures(VCalendar $calendar, IcsFeed $feed, CarbonImmutable $now, string $host): void
    {
        $closures = SchoolClosure::query()
            ->where('ends_on', '>=', $now->subMonths(self::MONTHS_BACK)->toDateString())
            ->where('starts_on', '<=', $now->addMonths(self::MONTHS_AHEAD)->toDateString())
            ->when($feed->person_id !== null, fn (Builder $query) => $query->where(
                fn (Builder $inner) => $inner->whereNull('person_id')->orWhere('person_id', $feed->person_id),
            ))
            ->orderBy('starts_on')
            ->get();

        foreach ($closures as $closure) {
            $vevent = $calendar->add('VEVENT', [
                'UID' => "closure-{$closure->id}@{$host}",
                'SUMMARY' => $closure->title,
                'DTSTAMP' => ($closure->updated_at ?? $now)->setTimezone('UTC'),
                'TRANSP' => 'TRANSPARENT',
            ]);
            // ends_on is the last free day; DTEND is exclusive.
            $vevent->add('DTSTART', $closure->starts_on->format('Ymd'), ['VALUE' => 'DATE']);
            $vevent->add('DTEND', $closure->ends_on->addDay()->format('Ymd'), ['VALUE' => 'DATE']);
        }
    }
}
