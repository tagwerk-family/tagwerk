<?php

declare(strict_types=1);

namespace App\Integrations\Plugins;

use App\Models\PluginSetting;
use Fahoba\PluginApi\Plugins\Extension;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Contracts\Container\Container;

/**
 * The installed extensions and whether the operator switched them on.
 *
 * Read once per request (scoped): a switch flipped in the admin applies
 * from the next request on, in every process, without a cache to clear.
 */
#[Scoped]
final class Extensions
{
    /** @var array<string, PluginSetting>|null */
    private ?array $rows = null;

    public function __construct(
        private readonly PluginCatalog $catalog,
        private readonly Container $container,
    ) {}

    /** @return array<string, Extension> every installed one, on or off */
    public function installed(): array
    {
        return array_map(
            fn (string $class): Extension => $this->container->make($class),
            $this->catalog->offered('extensions'),
        );
    }

    /** @return array<string, Extension> */
    public function active(): array
    {
        return array_filter($this->installed(), fn (string $key): bool => $this->enabled($key), ARRAY_FILTER_USE_KEY);
    }

    public function enabled(string $key): bool
    {
        return array_key_exists($key, $this->catalog->offered('extensions'))
            && ($this->rows()[$key]->enabled ?? false);
    }

    /** @return array<string, mixed> */
    public function settings(string $key): array
    {
        return $this->rows()[$key]->settings ?? [];
    }

    /** @param  array<string, mixed>  $settings */
    public function save(string $key, bool $enabled, array $settings): void
    {
        PluginSetting::query()->updateOrCreate(['key' => $key], ['enabled' => $enabled, 'settings' => $settings]);
        $this->rows = null;
    }

    /** @return array<string, PluginSetting> */
    private function rows(): array
    {
        return $this->rows ??= PluginSetting::query()->get()->keyBy('key')->all();
    }
}
