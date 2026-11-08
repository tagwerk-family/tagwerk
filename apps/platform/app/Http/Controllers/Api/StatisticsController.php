<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Person;
use App\Services\Statistics\StatisticsPeriod;
use App\Services\Statistics\TaskStatistics;
use App\Support\HouseholdContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Who did which task how often — the numbers behind the admin's statistics
 * page, for the app, the MCP assistant and anyone with an API token.
 *
 * Per-person maps are keyed by person id; `none` is ticked off without a
 * name. They are always JSON objects, never lists, so a client reads
 * `counts[id]` whatever ids occur. Stars ignore the task filter: a star
 * entry belongs to a person, not every one to a task.
 */
final class StatisticsController extends Controller
{
    public function __invoke(Request $request, TaskStatistics $statistics, HouseholdContext $household): JsonResponse
    {
        $filters = $request->validate([
            'period' => ['nullable', Rule::in(StatisticsPeriod::PRESETS)],
            'from' => ['nullable', 'required_if:period,custom', 'date_format:Y-m-d'],
            'until' => ['nullable', 'required_if:period,custom', 'date_format:Y-m-d'],
            'people' => ['nullable', 'array', 'max:50'],
            'people.*' => ['integer'],
            'task' => ['nullable', 'integer'],
        ]);

        $timezone = (string) $household->timezone();
        $today = CarbonImmutable::now($timezone);
        $period = StatisticsPeriod::fromFilters($filters, $today);

        // A custom span of years would derive every occurrence of it.
        if ($period->days() > 731) {
            return response()->json(['message' => __('Choose at most two years.')], 422);
        }

        $reliability = $statistics->reliability($period, $today);
        $stars = $statistics->stars($period, $timezone);
        $timeline = $statistics->timeline($period);

        return response()->json([
            'period' => [
                'from' => $period->from->toDateString(),
                'until' => $period->until->toDateString(),
                'days' => $period->days(),
                'bucket' => $period->bucket(),
            ],
            'people' => $statistics->people($period)
                ->map(fn (Person $person): array => ['id' => $person->id, 'name' => $person->name, 'color' => $person->color])
                ->values(),
            'totals' => [
                'done' => $statistics->completions($period)->count(),
                'due' => array_sum(array_column($reliability, 'due')),
                'kept' => array_sum(array_column($reliability, 'done')),
                'earned' => array_sum(array_column($stars, 'earned')),
                'spent' => array_sum(array_column($stars, 'spent')),
            ],
            'tasks' => array_map(fn (array $row): array => [
                'id' => $row['task_id'],
                'title' => $row['title'],
                'icon' => $row['icon'],
                'counts' => (object) $row['counts'],
                'total' => $row['total'],
            ], $statistics->matrix($period)),
            'timeline' => [
                'labels' => $timeline['labels'],
                'series' => (object) $timeline['series'],
            ],
            'weekdays' => (object) $statistics->weekdays($period),
            'reliability' => (object) $reliability,
            'stars' => (object) $stars,
        ]);
    }
}
