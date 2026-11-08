<?php

declare(strict_types=1);

namespace App\Filament\Statistics;

use Filament\Widgets\ChartWidget;

/**
 * Stars earned and spent per person. Two measures of the same unit side by
 * side; who it is reads from the axis, so the bars carry the measure's
 * colour, not the person's.
 */
final class StarsChart extends ChartWidget
{
    use ReadsStatistics;

    protected ?string $maxHeight = '280px';

    public function getHeading(): string
    {
        return __('Stars');
    }

    public function getDescription(): string
    {
        return __('Earned with tasks, day bonus and extra stars; spent on rewards. Gifts between siblings count for neither.');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /** @return array<string, mixed> */
    protected function getData(): array
    {
        $stars = $this->statistics()->stars($this->period(), $this->household()->timezone);
        $people = $this->seriesOf($stars);
        $bar = ['borderRadius' => 4, 'maxBarThickness' => 28, 'borderColor' => 'rgba(0, 0, 0, 0)', 'borderWidth' => ['left' => 1, 'right' => 1]];

        return [
            'labels' => array_column($people, 'name'),
            'datasets' => [
                ['label' => __('Earned'), 'data' => array_map(fn (array $person): int => $stars[$person['key']]['earned'], $people), 'backgroundColor' => '#d97706', ...$bar],
                ['label' => __('Spent'), 'data' => array_map(fn (array $person): int => $stars[$person['key']]['spent'], $people), 'backgroundColor' => '#64748b', ...$bar],
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['position' => 'bottom', 'labels' => ['usePointStyle' => true, 'boxHeight' => 8]]],
            'scales' => [
                'x' => ['grid' => ['display' => false]],
                'y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]],
            ],
        ];
    }
}
