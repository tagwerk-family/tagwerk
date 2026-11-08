<?php

declare(strict_types=1);

namespace App\Filament\Statistics;

use Filament\Widgets\Widget;

/**
 * Which task, how often, by whom — a table whose cells darken with the
 * count. A table rather than a chart: thirty tasks by four children is
 * read along rows and columns, and the numbers stay readable.
 */
final class TaskMatrix extends Widget
{
    use ReadsStatistics;

    protected string $view = 'filament.statistics.task-matrix';

    protected int|string|array $columnSpan = 'full';

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $rows = $this->statistics()->matrix($this->period());
        $present = [];
        $max = 1;

        foreach ($rows as $row) {
            foreach ($row['counts'] as $key => $count) {
                $present[$key] = true;
                $max = max($max, $count);
            }
        }

        return [
            'rows' => $rows,
            'columns' => $this->seriesOf($present),
            'max' => $max,
        ];
    }
}
