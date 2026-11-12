<?php

declare(strict_types=1);

namespace App\Areas;

use App\Enums\Feature;
use App\Integrations\Plugins\PluginCatalog;
use Fahoba\PluginApi\Areas\Area;
use Fahoba\PluginApi\Areas\OptIn;
use Fahoba\PluginApi\Areas\RequiresArea;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Contracts\Container\Container;

/**
 * Every area there is: the built-in `Feature` cases in their order, the
 * core's areas without a view (`CORE`), then the plugins' areas by key.
 *
 * Whatever lists all areas asks here, not `Feature::cases()` — otherwise an
 * installed plugin would be missing from the household's switches, the
 * menu and the allowed start areas.
 */
#[Scoped]
final class Areas
{
    /** Built-in areas that are no `Feature`: no view, nothing on the wall. */
    private const CORE = [ApiArea::KEY => ApiArea::class];

    /** @var array<string, Area>|null */
    private ?array $plugins = null;

    public function __construct(
        private readonly PluginCatalog $catalog,
        private readonly Container $container,
    ) {}

    /**
     * Built afresh on each call: the labels follow the request's language,
     * which may only be known after the first call.
     *
     * @return array<string, AreaDefinition>
     */
    public function all(): array
    {
        $all = [];

        foreach (Feature::cases() as $feature) {
            $all[$feature->value] = new AreaDefinition(
                key: $feature->value,
                label: $feature->label(),
                shortLabel: $feature->shortLabel(),
                description: $feature->description(),
                icon: $feature->icon(),
                inMenu: true,
                requires: $feature->requires()?->value,
            );
        }

        foreach ($this->plugins() as $key => $area) {
            $all[$key] = new AreaDefinition(
                key: $key,
                label: $area->label(),
                shortLabel: $area->shortLabel(),
                description: $area->description(),
                icon: $area->icon(),
                inMenu: $area->inMenu(),
                requires: $area instanceof RequiresArea ? $area->requires() : null,
                optIn: $area instanceof OptIn,
            );
        }

        return $all;
    }

    public function find(string $key): ?AreaDefinition
    {
        return $this->all()[$key] ?? null;
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return [...array_column(Feature::cases(), 'value'), ...array_keys($this->plugins())];
    }

    /** Whether the area is off until a household switches it on. */
    public function isOptIn(string $key): bool
    {
        return ($this->plugins()[$key] ?? null) instanceof OptIn;
    }

    /** The area a core or plugin area needs; `Feature::requires()` for the built-in ones. */
    public function requires(string $key): ?string
    {
        $area = $this->plugins()[$key] ?? null;

        return $area instanceof RequiresArea ? $area->requires() : null;
    }

    /** @return array<string, Area> core first, then the plugins' */
    private function plugins(): array
    {
        if ($this->plugins === null) {
            $offered = $this->catalog->offered('areas');
            ksort($offered);

            $this->plugins = array_map(fn (string $class): Area => $this->container->make($class), [...self::CORE, ...$offered]);
        }

        return $this->plugins;
    }
}
