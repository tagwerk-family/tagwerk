<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Households;

use Illuminate\Database\Eloquent\Builder;

/**
 * Restricts every query to the current household and sets `household_id`
 * on create — for any table that holds a household's data, a plugin's
 * included.
 *
 * Without a household nothing is visible — on purpose, so a forgotten
 * context leads to empty results instead of someone else's data.
 */
trait HouseholdScoped
{
    public static function bootHouseholdScoped(): void
    {
        static::addGlobalScope('household', function (Builder $builder): void {
            $builder->where(
                $builder->getModel()->qualifyColumn('household_id'),
                app(CurrentHousehold::class)->id(),
            );
        });

        static::creating(function (self $model): void {
            if ($model->household_id === null) {
                $model->household_id = app(CurrentHousehold::class)->id();
            }
        });
    }

    /**
     * For background processes that have to work across households.
     *
     * @return Builder<static>
     */
    public static function withoutHouseholdScope(): Builder
    {
        return static::query()->withoutGlobalScope('household');
    }
}
