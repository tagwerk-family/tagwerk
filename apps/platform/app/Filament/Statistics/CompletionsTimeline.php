<?php

declare(strict_types=1);

namespace App\Filament\Statistics;

use Filament\Widgets\ChartWidget;

/** Done per person over the period — by day, week or month, whatever fits. */
final class CompletionsTimeline extends ChartWidget
{
    use ReadsStatistics;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '320px';

    public function getHeading(): string
    {
        return __('Tasks done over time');
    }

    public function getDescription(): string
    {
        return match ($this->period()->bucket()) {
            'day' => __('Per day, per person'),
            'week' => __('Per week, per person'),
            default => __('Per month, per person'),
        };
    }

    protected function getType(): string
    {
        return 'line';
    }

    /** @return array<string, mixed> */
    protected function getData(): array
    {
        $timeline = $this->statistics()->timeline($this->period());

        return [
            'labels' => $timeline['labels'],
            'datasets' => array_map(fn (array $series): array => [
                'label' => $series['name'],
                'data' => $timeline['series'][$series['key']],
                'borderColor' => $series['color'],
                'backgroundColor' => $series['color'],
                'borderWidth' => 2,
                'pointRadius' => 0,
                'pointHoverRadius' => 5,
                'pointHitRadius' => 12,
                // Smooth, but never above or below the counts it passes through.
                'cubicInterpolationMode' => 'monotone',
            ], $this->seriesOf($timeline['series'])),
        ];
    }

    /** @return array<string, mixed> */
    protected function getOptions(): array
    {
        return [
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'plugins' => ['legend' => ['position' => 'bottom', 'labels' => ['usePointStyle' => true, 'boxHeight' => 8]]],
            'scales' => [
                'y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]],
                'x' => ['grid' => ['display' => false]],
            ],
        ];
    }
}
