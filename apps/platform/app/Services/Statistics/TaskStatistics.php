<?php

declare(strict_types=1);

namespace App\Services\Statistics;

use App\Models\Person;
use App\Models\PointEntry;
use App\Models\Task;
use App\Models\TaskCompletion;
use App\Services\TaskOccurrenceService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who did what, and how reliably — read from what is stored anyway.
 *
 * Done means a `task_completions` row that is not `skipped`, counted for the
 * person who did it (`person_id`); a row without one was ticked off without
 * a name. Due comes from `TaskOccurrenceService`, which derives occurrences
 * instead of storing them, and so only knows tasks that are still active:
 * the rate covers today's routines, the counts all history.
 *
 * Runs inside the household context like everything in the admin.
 */
final class TaskStatistics
{
    public function __construct(private readonly TaskOccurrenceService $occurrences) {}

    /**
     * The people the statistics name, in the family's order, narrowed by the
     * filter.
     *
     * @return Collection<int, Person>
     */
    public function people(StatisticsPeriod $period): Collection
    {
        return Person::query()
            ->ordered()
            ->when($period->personIds !== [], fn (Builder $query) => $query->whereKey($period->personIds))
            ->get();
    }

    /** @return Builder<TaskCompletion> */
    public function completions(StatisticsPeriod $period): Builder
    {
        return TaskCompletion::query()
            ->where('skipped', false)
            ->whereBetween('occurred_on', [$period->from->toDateString(), $period->until->toDateString()])
            ->when($period->personIds !== [], fn (Builder $query) => $query->whereIn('person_id', $period->personIds))
            ->when($period->taskId !== null, fn (Builder $query) => $query->where('task_id', $period->taskId));
    }

    /**
     * How often each task was done by whom; most done first. `null` as a key
     * is ticked off without a name.
     *
     * @return list<array{task_id: int, title: string, icon: ?string, counts: array<int|string, int>, total: int}>
     */
    public function matrix(StatisticsPeriod $period): array
    {
        $rows = $this->completions($period)
            ->selectRaw('task_id, person_id, count(*) as done')
            ->groupBy('task_id', 'person_id')
            ->toBase()
            ->get();

        $tasks = Task::withTrashed()->whereKey($rows->pluck('task_id')->unique()->all())->get()->keyBy('id');
        $matrix = [];

        foreach ($rows as $row) {
            $task = $tasks->get($row->task_id);

            if ($task === null) {
                continue;
            }

            $entry = $matrix[$row->task_id] ??= ['task_id' => (int) $row->task_id, 'title' => (string) $task->title, 'icon' => $task->icon, 'counts' => [], 'total' => 0];
            $entry['counts'][$row->person_id ?? 'none'] = (int) $row->done;
            $entry['total'] += (int) $row->done;
            $matrix[$row->task_id] = $entry;
        }

        usort($matrix, fn (array $a, array $b): int => [$b['total'], $a['title']] <=> [$a['total'], $b['title']]);

        return $matrix;
    }

    /**
     * Done per person and step of the timeline (`StatisticsPeriod::bucket()`),
     * every step present even when nothing happened in it.
     *
     * @return array{labels: list<string>, series: array<int|string, list<int>>}
     */
    public function timeline(StatisticsPeriod $period): array
    {
        $steps = $this->steps($period);
        $index = array_flip(array_keys($steps));
        $series = [];

        $rows = $this->completions($period)
            ->selectRaw('occurred_on, person_id, count(*) as done')
            ->groupBy('occurred_on', 'person_id')
            ->toBase()
            ->get();

        foreach ($rows as $row) {
            $key = $this->stepOf(CarbonImmutable::parse((string) $row->occurred_on), $period->bucket());
            $who = $row->person_id ?? 'none';
            $series[$who] ??= array_fill(0, count($steps), 0);
            $series[$who][$index[$key]] += (int) $row->done;
        }

        return ['labels' => array_values($steps), 'series' => $series];
    }

    /**
     * Done per weekday (Monday first) and person.
     *
     * @return array<int|string, list<int>>
     */
    public function weekdays(StatisticsPeriod $period): array
    {
        $series = [];

        $rows = $this->completions($period)
            ->selectRaw('extract(isodow from occurred_on)::int as weekday, person_id, count(*) as done')
            ->groupByRaw('1, person_id')
            ->toBase()
            ->get();

        foreach ($rows as $row) {
            $who = $row->person_id ?? 'none';
            $series[$who] ??= array_fill(0, 7, 0);
            $series[$who][(int) $row->weekday - 1] += (int) $row->done;
        }

        return $series;
    }

    /**
     * How much of what was someone's own got done: occurrences up to today
     * whose responsible person it is, and how many of them carry a
     * completion. "For everyone" belongs to nobody and stays out; a skipped
     * occurrence was not due.
     *
     * @return array<int, array{due: int, done: int}>
     */
    public function reliability(StatisticsPeriod $period, CarbonImmutable $today): array
    {
        $until = $period->until->min(CarbonImmutable::parse($today->toDateString()));

        if ($until->lessThan($period->from)) {
            return [];
        }

        $result = [];

        foreach ($this->occurrences->between($period->from, $until) as $occurrence) {
            $person = $occurrence['person'];
            $completion = $occurrence['completion'];

            if ($person === null
                || ($period->personIds !== [] && ! in_array($person->id, $period->personIds, true))
                || ($period->taskId !== null && $occurrence['task']->id !== $period->taskId)
                || $completion?->skipped) {
                continue;
            }

            $result[$person->id] ??= ['due' => 0, 'done' => 0];
            $result[$person->id]['due']++;
            $result[$person->id]['done'] += $completion !== null ? 1 : 0;
        }

        return $result;
    }

    /**
     * Stars earned (tasks, day bonus, extra stars; gifts move stars but earn
     * none) and spent on rewards, per person. `created_at` holds UTC without
     * a zone, so the household's days are turned into UTC bounds.
     *
     * @return array<int, array{earned: int, spent: int}>
     */
    public function stars(StatisticsPeriod $period, string $timezone): array
    {
        $start = CarbonImmutable::parse($period->from->toDateString(), $timezone)->utc();
        $end = CarbonImmutable::parse($period->until->toDateString(), $timezone)->addDay()->utc();

        $rows = PointEntry::query()
            ->whereNull('gift_id')
            ->where('created_at', '>=', $start->format('Y-m-d H:i:s'))
            ->where('created_at', '<', $end->format('Y-m-d H:i:s'))
            ->when($period->personIds !== [], fn (Builder $query) => $query->whereIn('person_id', $period->personIds))
            ->selectRaw('person_id,
                coalesce(sum(points) filter (where reward_redemption_id is null and points > 0), 0) as earned,
                coalesce(-sum(points) filter (where reward_redemption_id is not null), 0) as spent')
            ->groupBy('person_id')
            ->toBase()
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $result[(int) $row->person_id] = ['earned' => (int) $row->earned, 'spent' => (int) $row->spent];
        }

        return $result;
    }

    /**
     * The steps of the timeline, keyed by their first day.
     *
     * @return array<string, string>
     */
    private function steps(StatisticsPeriod $period): array
    {
        $bucket = $period->bucket();
        $steps = [];
        $cursor = CarbonImmutable::parse($this->stepOf($period->from, $bucket));

        while ($cursor->lessThanOrEqualTo($period->until)) {
            $steps[$cursor->toDateString()] = match ($bucket) {
                'day' => $cursor->isoFormat('D. MMM'),
                'week' => __('Week :number', ['number' => $cursor->isoWeek]),
                default => $cursor->isoFormat('MMM YYYY'),
            };
            $cursor = match ($bucket) {
                'day' => $cursor->addDay(),
                'week' => $cursor->addWeek(),
                default => $cursor->addMonthNoOverflow(),
            };
        }

        return $steps;
    }

    private function stepOf(CarbonImmutable $day, string $bucket): string
    {
        return match ($bucket) {
            'day' => $day->toDateString(),
            'week' => $day->startOfWeek(CarbonImmutable::MONDAY)->toDateString(),
            default => $day->startOfMonth()->toDateString(),
        };
    }
}
