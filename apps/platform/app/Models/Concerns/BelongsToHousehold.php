<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Household;
use Fahoba\PluginApi\Households\HouseholdScoped;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The plugin API's household scope plus the relation to the core's model —
 * core and plugins share one implementation of the rule that keeps
 * households apart.
 */
trait BelongsToHousehold
{
    use HouseholdScoped;

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }
}
