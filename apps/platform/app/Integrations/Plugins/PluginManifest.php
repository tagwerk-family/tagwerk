<?php

declare(strict_types=1);

namespace App\Integrations\Plugins;

use Illuminate\Support\Facades\File;
use Throwable;

/**
 * The installed Fahoba plugins, read from Composer's `installed.json`: every
 * package with `extra.fahoba`.
 *
 * Kept in `bootstrap/cache/fahoba-plugins.php` and rebuilt when Composer
 * changed something (the file's modification time), as Laravel does with its
 * own package manifest — the app reads this on every request.
 */
final class PluginManifest
{
    /** Raised when a package entry gains a field: a cache of the old shape is read again. */
    private const SHAPE = 4;

    /** Stands in for an `extra.fahoba.csp` that is no map of host lists. */
    public const MALFORMED = ['malformed' => []];

    private readonly string $installed;

    private readonly string $cache;

    /** @var list<array{name: string, version: string, license: list<string>, provider: ?string, frontend: bool, csp: array<string, list<string>>}>|null */
    private ?array $packages = null;

    public function __construct(?string $installed = null, ?string $cache = null)
    {
        $this->installed = $installed ?? base_path('vendor/composer/installed.json');
        $this->cache = $cache ?? base_path('bootstrap/cache/fahoba-plugins.php');
    }

    /** @return list<array{name: string, version: string, license: list<string>, provider: ?string, frontend: bool, locales: ?string, complete_for: ?string, csp: array<string, list<string>>}> */
    public function packages(): array
    {
        if ($this->packages !== null) {
            return $this->packages;
        }

        $modified = is_file($this->installed) ? (int) filemtime($this->installed) : 0;
        $cached = is_file($this->cache) ? require $this->cache : null;

        if (is_array($cached) && ($cached['modified'] ?? null) === $modified && ($cached['shape'] ?? null) === self::SHAPE) {
            return $this->packages = $cached['packages'];
        }

        $this->packages = $this->read();

        try {
            // Written aside and renamed: a request reading meanwhile sees the old file or the new one, never half.
            File::replace($this->cache, '<?php return '.var_export(['modified' => $modified, 'shape' => self::SHAPE, 'packages' => $this->packages], true).';'.PHP_EOL);
        } catch (Throwable) {
            // A read-only cache directory costs a parse per request, nothing more.
        }

        return $this->packages;
    }

    /** @return list<array{name: string, version: string, license: list<string>, provider: ?string, frontend: bool, locales: ?string, complete_for: ?string, csp: array<string, list<string>>}> */
    private function read(): array
    {
        $installed = is_file($this->installed) ? json_decode((string) file_get_contents($this->installed), true) : [];
        $packages = [];

        foreach ((array) ($installed['packages'] ?? $installed) as $package) {
            $extra = $package['extra']['fahoba'] ?? null;

            if (! is_array($extra)) {
                continue;
            }

            $packages[] = [
                'name' => (string) $package['name'],
                'version' => (string) ($package['version'] ?? ''),
                'license' => array_values((array) ($package['license'] ?? [])),
                'provider' => isset($extra['provider']) ? (string) $extra['provider'] : null,
                'frontend' => isset($extra['frontend']),
                'locales' => $this->locales($package, $extra['frontend'] ?? null),
                'complete_for' => is_string($extra['language']['complete-for'] ?? null) ? $extra['language']['complete-for'] : null,
                'csp' => self::csp($extra['csp'] ?? []),
            ];
        }

        return $packages;
    }

    /**
     * Where a plugin layer keeps its translations — language packs serve a
     * plugin's own `<code>.json` from there along with theirs.
     *
     * @param  array<string, mixed>  $package
     */
    private function locales(array $package, mixed $frontend): ?string
    {
        if (! is_string($frontend) || ! is_string($package['install-path'] ?? null)) {
            return null;
        }

        $path = realpath(dirname($this->installed).'/'.$package['install-path'].'/'.$frontend.'/i18n/locales');

        return $path === false ? null : $path;
    }

    /**
     * Directive => list of strings, or MALFORMED — never a cast of whatever
     * the package wrote: a warning here would stop every request.
     *
     * @return array<string, list<string>>
     */
    private static function csp(mixed $csp): array
    {
        if (! is_array($csp)) {
            return self::MALFORMED;
        }

        foreach ($csp as $directive => $hosts) {
            if (! is_string($directive) || ! is_array($hosts) || ! array_is_list($hosts)) {
                return self::MALFORMED;
            }

            foreach ($hosts as $host) {
                if (! is_string($host)) {
                    return self::MALFORMED;
                }
            }
        }

        return $csp;
    }
}
