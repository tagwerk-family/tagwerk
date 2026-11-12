<?php

declare(strict_types=1);

namespace App\Dashboard;

use App\Integrations\Plugins\PluginCatalog;
use App\Models\Household;
use Fahoba\PluginApi\Dashboard\DataWidget;
use Fahoba\PluginApi\Dashboard\HasSettings;
use Illuminate\Contracts\Container\Container;

/**
 * The widgets that exist — and the ones a household may use.
 *
 * Same approach as for providers: the list lives in `config/fahoba.php`,
 * not in code. Adding a widget means an entry there and a component with the
 * same key on the wall.
 *
 * Plugins need no component: they offer a `DataWidget` through the
 * `PluginCatalog`, and the wall renders its content itself.
 */
final class WidgetRegistry
{
    public function __construct(
        private readonly Container $container,
        private readonly PluginCatalog $plugins,
    ) {}

    /** @return array<string, WidgetDefinition> */
    public function all(): array
    {
        $widgets = [];

        /** @var array<string, array<string, mixed>> $configured */
        $configured = (array) config('fahoba.widgets', []);

        foreach ($configured as $key => $entry) {
            $widgets[(string) $key] = new WidgetDefinition(
                key: (string) $key,
                label: (string) ($entry['label'] ?? $key),
                description: (string) ($entry['description'] ?? ''),
                area: isset($entry['feature']) ? (string) $entry['feature'] : null,
                w: (int) ($entry['w'] ?? 4),
                h: (int) ($entry['h'] ?? 3),
            );
        }

        foreach (array_keys($this->plugins->offered('widgets')) as $key) {
            $widget = $this->dataWidget($key);

            if ($widget !== null) {
                $widgets[$key] = new WidgetDefinition(
                    key: $key,
                    label: $widget->label(),
                    description: $widget->description(),
                    area: $widget->area(),
                    w: 4,
                    h: 3,
                    generic: true,
                    settings: $widget instanceof HasSettings ? $widget->settings() : [],
                );
            }
        }

        return $widgets;
    }

    /** The plugin widget behind a key; null for built-in ones. */
    public function dataWidget(string $key): ?DataWidget
    {
        $class = $this->plugins->offered('widgets')[$key] ?? null;

        if ($class === null) {
            return null;
        }

        $widget = $this->container->make($class);

        return $widget instanceof DataWidget ? $widget : null;
    }

    public function find(string $key): ?WidgetDefinition
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * The widgets this household can use.
     *
     * A widget whose area is disabled appears neither in the grid nor in the
     * picker — otherwise a tile would show numbers from an area the household
     * does not use.
     *
     * @return array<string, WidgetDefinition>
     */
    public function forHousehold(Household $household): array
    {
        return array_filter(
            $this->all(),
            static fn (WidgetDefinition $widget): bool => $widget->area === null
                || $household->has($widget->area),
        );
    }
}
