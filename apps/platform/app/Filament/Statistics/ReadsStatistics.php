<?php

declare(strict_types=1);

namespace App\Filament\Statistics;

use App\Models\Household;
use App\Models\Person;
use App\Services\Statistics\StatisticsPeriod;
use App\Services\Statistics\TaskStatistics;
use App\Support\HouseholdContext;
use Carbon\CarbonImmutable;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Support\Collection;

/**
 * What every statistics widget shares: the page's filters as a period, the
 * household's today, and how a person is named and coloured. A person keeps
 * their colour in every chart — identity follows the person, never the rank.
 */
trait ReadsStatistics
{
    use InteractsWithPageFilters;

    /** The colour of "ticked off without a name": neutral, it is nobody. */
    protected const NOBODY_COLOR = '#9ca3af';

    public static function canView(): bool
    {
        return app(HouseholdContext::class)->hasId();
    }

    protected function household(): Household
    {
        return once(fn (): Household => Household::query()->findOrFail(app(HouseholdContext::class)->id()));
    }

    protected function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->household()->timezone);
    }

    protected function period(): StatisticsPeriod
    {
        return StatisticsPeriod::fromFilters($this->pageFilters, $this->today());
    }

    protected function statistics(): TaskStatistics
    {
        return app(TaskStatistics::class);
    }

    /** @return Collection<int, Person> */
    protected function people(): Collection
    {
        return $this->statistics()->people($this->period());
    }

    /**
     * People in the family's order, then "without a name" when it occurs.
     *
     * @param  array<int|string, mixed>  $series
     * @return list<array{key: int|string, name: string, color: string}>
     */
    protected function seriesOf(array $series): array
    {
        $order = [];

        foreach ($this->people() as $person) {
            if (array_key_exists($person->id, $series)) {
                $order[] = ['key' => $person->id, 'name' => (string) $person->name, 'color' => self::color($person)];
            }
        }

        if (array_key_exists('none', $series)) {
            $order[] = ['key' => 'none', 'name' => __('Without a name'), 'color' => self::NOBODY_COLOR];
        }

        return $order;
    }

    protected static function color(Person $person): string
    {
        return preg_match('/^#[0-9a-f]{6}$/i', (string) $person->color) === 1 ? (string) $person->color : self::NOBODY_COLOR;
    }
}
