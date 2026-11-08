<?php

declare(strict_types=1);

namespace App\Filament\Statistics;

use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;

/** On which days things get done — stacked by person. */
final class WeekdayChart extends ChartWidget
{
    use ReadsStatistics;

    protected ?string $maxHeight = '280px';

    public function getHeading(): string
    {
        return __('Weekdays');
    }

    public function getDescription(): string
    {
        return __('Tasks done per weekday');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /** @return array<string, mixed> */
    protected function getData(): array
    {
        $weekdays = $this->statistics()->weekdays($this->period());
        $monday = CarbonImmutable::parse('2026-01-05');

        return [
            // Names from the locale, Monday first.
            'labels' => array_map(fn (int $day): string => $monday->addDays($day)->isoFormat('dd'), range(0, 6)),
            'datasets' => array_map(fn (array $series): array => [
                'label' => $series['name'],
                'data' => $weekdays[$series['key']],
                'backgroundColor' => $series['color'],
                // A thin gap in the surface colour between stacked segments.
                'borderColor' => 'rgba(0, 0, 0, 0)',
                'borderWidth' => ['top' => 2],
                'borderRadius' => 4,
                'maxBarThickness' => 36,
            ], $this->seriesOf($weekdays)),
        ];
    }

    /** @return array<string, mixed> */
    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['position' => 'bottom', 'labels' => ['usePointStyle' => true, 'boxHeight' => 8]]],
            'scales' => [
                'x' => ['stacked' => true, 'grid' => ['display' => false]],
                'y' => ['stacked' => true, 'beginAtZero' => true, 'ticks' => ['precision' => 0]],
            ],
        ];
    }
}
