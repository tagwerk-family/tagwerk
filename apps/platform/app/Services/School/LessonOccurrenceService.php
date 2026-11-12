<?php

declare(strict_types=1);

namespace App\Services\School;

use App\Enums\LessonCadence;
use App\Enums\LessonChangeKind;
use App\Models\Household;
use App\Models\Lesson;
use App\Models\LessonChange;
use App\Models\LessonPeriod;
use App\Models\Person;
use App\Models\SchoolClosure;
use App\Services\Holidays\HolidayService;
use Carbon\CarbonImmutable;
use Fahoba\PluginApi\Holidays\HolidayEntry;
use Illuminate\Support\Collection;

/**
 * Expands the timetable for a period.
 *
 * Stores nothing — the same approach as TaskOccurrenceService for routines.
 * A school-specific day off added later therefore takes effect immediately
 * and retroactively, without any rows to repair.
 */
final class LessonOccurrenceService
{
    public function __construct(private readonly HolidayService $holidays) {}

    /**
     * @return Collection<int, LessonOccurrence>
     */
    public function between(
        Household $household,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?Person $child = null,
    ): Collection {
        $lessons = $this->lessons($household, $child);

        if ($lessons->isEmpty()) {
            return new Collection;
        }

        $periods = $this->periods($lessons);
        $changes = $this->changes($lessons, $from, $to);
        $closures = $this->closures($household);
        $holidays = $this->officialHolidays($household, $from, $to);
        $zone = $household->timezone ?? config('app.timezone');

        $occurrences = new Collection;

        /*
         * From here on we compute with plain dates, without a time zone.
         * Otherwise 4 January in Vienna would lie an hour before 4 January in
         * UTC, and an anchor day would fall out of step depending on the
         * season. The zone only comes back in when assembling the time.
         */
        $last = CarbonImmutable::parse($to->toDateString());

        for (
            $day = CarbonImmutable::parse($from->toDateString());
            $day->lessThanOrEqualTo($last);
            $day = $day->addDay()
        ) {
            foreach ($lessons as $lesson) {
                if ($lesson->weekday !== $day->dayOfWeekIso) {
                    continue;
                }

                if ($this->isDayOff($day, (int) $lesson->person_id, $closures, $holidays)) {
                    continue;
                }

                if (! $this->isDue($lesson, $day, $closures, $holidays)) {
                    continue;
                }

                $occurrence = $this->occurrence(
                    $lesson,
                    $day,
                    $periods,
                    $changes->get($lesson->id.'@'.$day->toDateString()),
                    (string) $zone,
                );

                if ($occurrence !== null) {
                    $occurrences->push($occurrence);
                }
            }
        }

        return $occurrences
            ->sortBy(fn (LessonOccurrence $occurrence): int => $occurrence->startsAt->getTimestamp())
            ->values();
    }

    /**
     * Decides whether the lesson takes place on this day at all.
     *
     * @param  Collection<int, SchoolClosure>  $closures
     * @param  Collection<string, HolidayEntry>  $holidays
     */
    private function isDue(
        Lesson $lesson,
        CarbonImmutable $day,
        Collection $closures,
        Collection $holidays,
    ): bool {
        if ($lesson->interval <= 1) {
            return true;
        }

        if ($day->lessThan($lesson->anchor_on)) {
            return false;
        }

        return match ($lesson->cadence) {
            /*
             * Pure date arithmetic: the calendar week decides. Holidays drop
             * the lessons that fall into them but do not shift the cadence —
             * most schools do it that way, because then nobody can miscount.
             */
            LessonCadence::Calendar => (int) round(
                $lesson->anchor_on->startOfWeek()->diffInWeeks($day->startOfWeek())
            ) % $lesson->interval === 0,

            /*
             * Only weeks with teaching on this weekday count here. Counting
             * starts at the anchor and not at the requested period, otherwise
             * the cadence would shift depending on which week is being shown.
             * TaskOccurrenceService counts from `due_on` for the same reason.
             */
            LessonCadence::SchoolWeeks => $this->schoolWeeks(
                $lesson,
                $day,
                $closures,
                $holidays,
            ) % $lesson->interval === 1,
        };
    }

    /**
     * Which teaching week of this lesson the day falls into — 1 for the
     * first.
     *
     * @param  Collection<int, SchoolClosure>  $closures
     * @param  Collection<string, HolidayEntry>  $holidays
     */
    private function schoolWeeks(
        Lesson $lesson,
        CarbonImmutable $day,
        Collection $closures,
        Collection $holidays,
    ): int {
        $counted = 0;
        $week = $lesson->anchor_on->startOfWeek();
        $target = $day->startOfWeek();

        while ($week->lessThanOrEqualTo($target)) {
            $teachingDay = $week->addDays($lesson->weekday - 1);

            if (! $this->isDayOff($teachingDay, (int) $lesson->person_id, $closures, $holidays)) {
                $counted++;
            }

            $week = $week->addWeek();
        }

        return $counted;
    }

    /**
     * @param  Collection<int, SchoolClosure>  $closures
     * @param  Collection<string, HolidayEntry>  $holidays
     */
    private function isDayOff(
        CarbonImmutable $day,
        int $personId,
        Collection $closures,
        Collection $holidays,
    ): bool {
        if ($holidays->has($day->toDateString())) {
            return true;
        }

        return $closures->contains(
            fn (SchoolClosure $closure): bool => $closure->covers($day, $personId),
        );
    }

    /**
     * Builds the occurrence and applies the change.
     *
     * @param  Collection<string, LessonPeriod>  $periods
     */
    private function occurrence(
        Lesson $lesson,
        CarbonImmutable $day,
        Collection $periods,
        ?LessonChange $change,
        string $zone,
    ): ?LessonOccurrence {
        $times = $this->times($lesson, $periods);

        if ($times === null) {
            // A period number without a matching bell row: show nothing
            // instead of inventing a time.
            return null;
        }

        [$start, $end] = $times;
        $title = $lesson->title;
        $originalTitle = null;

        if ($change?->kind === LessonChangeKind::Replaced) {
            $originalTitle = $lesson->title;
            $title = $change->title ?? $lesson->title;

            if (is_string($change->starts_at) && is_string($change->ends_at)) {
                $start = $change->starts_at;
                $end = $change->ends_at;
            }
        }

        return new LessonOccurrence(
            lessonId: (int) $lesson->id,
            personId: (int) $lesson->person_id,
            date: $day,
            startsAt: $this->moment($day, $start, $zone),
            endsAt: $this->moment($day, $end, $zone),
            title: $title,
            originalTitle: $originalTitle,
            periodNumber: $lesson->period_number,
            change: $change?->kind,
            color: $change?->kind === LessonChangeKind::Replaced ? null : $lesson->color,
        );
    }

    /**
     * The lesson's times: from the bell schedule or from the row itself.
     *
     * @param  Collection<string, LessonPeriod>  $periods
     * @return array{0: string, 1: string}|null
     */
    private function times(Lesson $lesson, Collection $periods): ?array
    {
        if (! $lesson->usesPeriod()) {
            return is_string($lesson->starts_at) && is_string($lesson->ends_at)
                ? [$lesson->starts_at, $lesson->ends_at]
                : null;
        }

        $period = $periods->get($lesson->person_id.'/'.$lesson->period_number);

        return $period instanceof LessonPeriod
            ? [(string) $period->starts_at, (string) $period->ends_at]
            : null;
    }

    /**
     * Date and time are only combined here — in the household's time zone,
     * not the server's.
     */
    private function moment(CarbonImmutable $day, string $time, string $zone): CarbonImmutable
    {
        return CarbonImmutable::parse($day->toDateString().' '.$time, $zone);
    }

    /** @return Collection<int, Lesson> */
    private function lessons(Household $household, ?Person $child): Collection
    {
        return Lesson::query()
            ->where('household_id', $household->id)
            ->where('active', true)
            ->when($child !== null, fn ($query) => $query->where('person_id', $child->id))
            ->orderBy('weekday')
            ->get();
    }

    /**
     * The bell schedule, keyed by "person/number".
     *
     * @param  Collection<int, Lesson>  $lessons
     * @return Collection<string, LessonPeriod>
     */
    private function periods(Collection $lessons): Collection
    {
        return LessonPeriod::query()
            ->whereIn('person_id', $lessons->pluck('person_id')->unique()->all())
            ->get()
            ->keyBy(fn (LessonPeriod $period): string => $period->person_id.'/'.$period->number);
    }

    /**
     * The changes, keyed by "lesson@date".
     *
     * @param  Collection<int, Lesson>  $lessons
     * @return Collection<string, LessonChange>
     */
    private function changes(Collection $lessons, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return LessonChange::query()
            ->whereIn('lesson_id', $lessons->pluck('id')->all())
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->keyBy(fn (LessonChange $change): string => $change->lesson_id.'@'.$change->date->toDateString());
    }

    /**
     * All of the household's school closures.
     *
     * Deliberately not limited to the requested period: the school-week
     * cadence counts from the anchor and therefore also needs the holidays
     * before it.
     *
     * @return Collection<int, SchoolClosure>
     */
    private function closures(Household $household): Collection
    {
        return SchoolClosure::query()
            ->where('household_id', $household->id)
            ->get();
    }

    /**
     * Public holidays, keyed by date.
     *
     * Official ones only: Yasumi also returns observances on which there is
     * school.
     *
     * @return Collection<string, HolidayEntry>
     */
    private function officialHolidays(
        Household $household,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): Collection {
        /*
         * A year of lead time, because the school-week cadence counts back to
         * the anchor and needs old holidays for that too.
         */
        return $this->holidays
            ->between($household, $from->subYear(), $to)
            ->filter(fn (HolidayEntry $holiday): bool => $holiday->official)
            ->keyBy(fn (HolidayEntry $holiday): string => $holiday->date->toDateString());
    }
}
