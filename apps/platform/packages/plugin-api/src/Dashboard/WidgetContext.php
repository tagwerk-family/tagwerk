<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Dashboard;

/** The household a tile is drawn for — what a widget may know about it. */
final readonly class WidgetContext
{
    /** @param list<string> $areas the keys of the areas the household has switched on */
    public function __construct(
        public int $householdId,
        public string $timezone,
        public string $locale,
        public array $areas,
    ) {}

    public function has(string $area): bool
    {
        return in_array($area, $this->areas, true);
    }
}
