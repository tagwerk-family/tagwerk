<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Households;

/**
 * Dispatched before a household and everything it owns are deleted.
 *
 * A listener that throws stops the deletion — a subscription that could not
 * be cancelled must not outlive the household it pays for.
 */
final readonly class HouseholdDeleting
{
    public function __construct(public int $householdId) {}
}
