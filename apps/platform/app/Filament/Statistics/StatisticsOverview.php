<?php

declare(strict_types=1);

namespace App\Filament\Statistics;

use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** The period in four numbers, before any chart. */
final class StatisticsOverview extends StatsOverviewWidget
{
    use ReadsStatistics;

    protected int|string|array $columnSpan = 'full';

    /** @return array<int, Stat> */
    protected function getStats(): array
    {
        $period = $this->period();
        $statistics = $this->statistics();

        $done = $statistics->completions($period)->count();

        $reliability = $statistics->reliability($period, $this->today());
        $due = array_sum(array_column($reliability, 'due'));
        $kept = array_sum(array_column($reliability, 'done'));

        $stars = $statistics->stars($period, $this->household()->timezone);
        $earned = array_sum(array_column($stars, 'earned'));

        $byPerson = $statistics->completions($period)
            ->whereNotNull('person_id')
            ->selectRaw('person_id, count(*) as done')
            ->groupBy('person_id')
            ->orderByDesc('done')
            ->toBase()
            ->first();
        $busiest = $byPerson === null ? null : $this->people()->firstWhere('id', (int) $byPerson->person_id);

        return [
            Stat::make(__('Tasks done'), number_format($done, 0, ',', '.'))
                ->description(trans_choice(':count day|:count days', $period->days()))
                ->descriptionIcon(Heroicon::OutlinedCalendarDays),

            Stat::make(__('Own tasks done'), $due === 0 ? '–' : round($kept / $due * 100).' %')
                ->description($due === 0 ? __('Nothing was due') : __(':done of :due', ['done' => $kept, 'due' => $due]))
                ->descriptionIcon(Heroicon::OutlinedCheckBadge),

            Stat::make(__('Stars earned'), number_format($earned, 0, ',', '.'))
                ->description(__(':spent spent on rewards', ['spent' => array_sum(array_column($stars, 'spent'))]))
                ->descriptionIcon(Heroicon::OutlinedStar),

            Stat::make(__('Did the most'), $busiest?->name ?? '–')
                ->description($busiest === null ? __('Nothing done yet') : trans_choice(':count task|:count tasks', (int) $byPerson->done))
                ->descriptionIcon(Heroicon::OutlinedTrophy),
        ];
    }
}
