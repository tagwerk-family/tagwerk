<?php

declare(strict_types=1);

namespace App\Filament\Statistics;

use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

/**
 * Of what was someone's own, how much got done — the share, because the
 * counts differ by age and routine. The tooltip names the counts.
 */
final class ReliabilityChart extends ChartWidget
{
    use ReadsStatistics;

    protected ?string $maxHeight = '280px';

    public function getHeading(): string
    {
        return __('Own tasks done');
    }

    public function getDescription(): string
    {
        return __('Share of the tasks someone was responsible for, up to today. Only active tasks count.');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /** @return array<string, mixed> */
    protected function getData(): array
    {
        $reliability = $this->statistics()->reliability($this->period(), $this->today());
        $people = $this->seriesOf($reliability);

        return [
            'labels' => array_column($people, 'name'),
            'datasets' => [[
                'label' => __('Done'),
                'data' => array_map(fn (array $person): float => round($reliability[$person['key']]['done'] / $reliability[$person['key']]['due'] * 100, 1), $people),
                // The tooltip's text, ready made: the options are written into an
                // HTML attribute, where a quoted string from PHP would end it.
                'counts' => array_map(fn (array $person): string => __(':done of :due', $reliability[$person['key']]), $people),
                'backgroundColor' => array_column($people, 'color'),
                'borderRadius' => 4,
                'borderSkipped' => 'start',
                'maxBarThickness' => 28,
            ]],
        ];
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
            {
                indexAxis: 'y',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (item) => item.formattedValue + ' % · ' + item.dataset.counts[item.dataIndex],
                        },
                    },
                },
                scales: {
                    x: { min: 0, max: 100, ticks: { callback: (value) => value + ' %' } },
                    y: { grid: { display: false } },
                },
            }
        JS);
    }
}
