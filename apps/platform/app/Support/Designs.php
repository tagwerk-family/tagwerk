<?php

declare(strict_types=1);

namespace App\Support;

use App\Integrations\Plugins\PluginCatalog;
use Fahoba\PluginApi\Plugins\LoadReport;
use Fahoba\PluginApi\Themes\Stylesheet;
use Fahoba\PluginApi\Themes\Theme;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * The designs installed packages bring (`offer('themes', …)`), checked
 * once they are asked for, and served as files.
 *
 * A stylesheet that breaks a rule withdraws its package like any other
 * plugin failure. What is served carries a hash of everything it is made
 * of, so the address changes with the design and can be cached for good.
 */
#[Singleton]
final class Designs
{
    private ?string $checked = null;

    /** @var array<string, array{theme: Theme, css: string, hash: string, assets: array<string, string>}> */
    private array $designs = [];

    public function __construct(
        private readonly PluginCatalog $catalog,
        private readonly LoadReport $report,
        private readonly Container $container,
    ) {}

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    /** @return array{theme: Theme, css: string, hash: string, assets: array<string, string>}|null */
    public function get(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    /** @return array{key: string, name: string, url: string}|null */
    public function describe(?string $key): ?array
    {
        $design = $key === null ? null : $this->get($key);

        return $design === null ? null : [
            'key' => $key,
            'name' => $design['theme']->name(),
            'url' => "/api/design/{$key}/{$design['hash']}.css",
        ];
    }

    /** @return array<string, string> key => name, for choosers */
    public function options(): array
    {
        return array_map(static fn (array $design): string => $design['theme']->name(), $this->all());
    }

    /** @return array<string, array{theme: Theme, css: string, hash: string, assets: array<string, string>}> */
    private function all(): array
    {
        $offered = $this->catalog->offered('themes');
        $signature = json_encode($offered).':'.count($this->report->failures());

        if ($this->checked === $signature) {
            return $this->designs;
        }

        $designs = [];

        foreach ($offered as $key => $class) {
            $package = $this->catalog->packageOf('themes', $key) ?? PluginCatalog::unowned($key);

            try {
                /** @var Theme $theme */
                $theme = $this->container->make($class);
                $sheet = trim($theme->name()) === '' ? 'A theme needs a name.' : Stylesheet::read($theme->stylesheet());
            } catch (Throwable $exception) {
                $this->report->fail($package, $exception);

                continue;
            }

            if (is_string($sheet)) {
                $this->report->fail($package, $sheet);

                continue;
            }

            $hash = substr(hash('sha256', (string) file_get_contents($theme->stylesheet()).implode('', array_map(
                static fn (string $path): string => hash_file('sha256', $path) ?: '',
                $sheet['assets'],
            ))), 0, 12);

            $designs[$key] = [
                'theme' => $theme,
                'css' => Stylesheet::serve($sheet, "/api/design/{$key}/{$hash}"),
                'hash' => $hash,
                'assets' => $sheet['assets'],
            ];
        }

        ksort($designs);

        $this->designs = $designs;
        $this->checked = json_encode($this->catalog->offered('themes')).':'.count($this->report->failures());

        return $designs;
    }
}
