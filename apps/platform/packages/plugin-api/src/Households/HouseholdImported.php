<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Households;

/**
 * A household moved in from another server, with new ids. `importedBy` is
 * the account that brought it; null when the operator imported by command.
 */
final readonly class HouseholdImported
{
    public function __construct(
        public int $householdId,
        public ?int $importedBy,
    ) {}
}
