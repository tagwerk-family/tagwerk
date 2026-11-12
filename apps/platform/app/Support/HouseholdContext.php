<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Household;
use Fahoba\PluginApi\Households\CurrentHousehold;

/**
 * Holds the active household for the duration of a request or job.
 *
 * Registered as a singleton; the global scope from BelongsToHousehold reads
 * the restriction for every query from here.
 */
final class HouseholdContext implements CurrentHousehold
{
    private ?int $householdId = null;

    /** The row behind the id, loaded once when a plugin asks for more than the id. */
    private ?Household $household = null;

    public function setId(?int $id): void
    {
        $this->householdId = $id;
        // A queue worker keeps this singleton across jobs: never carry a row over.
        $this->household = null;
    }

    public function id(): ?int
    {
        return $this->householdId;
    }

    public function name(): ?string
    {
        return $this->household()?->name;
    }

    public function timezone(): ?string
    {
        return $this->household()?->timezone;
    }

    public function locale(): ?string
    {
        return $this->household()?->locale;
    }

    public function hasId(): bool
    {
        return $this->householdId !== null;
    }

    /**
     * Runs the callback with a different household and restores the previous
     * one afterwards.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function forId(?int $id, callable $callback): mixed
    {
        $previous = $this->householdId;
        $this->householdId = $id;
        $this->household = null;

        try {
            return $callback();
        } finally {
            $this->householdId = $previous;
        }
    }

    private function household(): ?Household
    {
        if ($this->householdId === null) {
            return null;
        }

        if ($this->household?->getKey() !== $this->householdId) {
            $this->household = Household::query()->find($this->householdId);
        }

        return $this->household;
    }
}
