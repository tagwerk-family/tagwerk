<?php

declare(strict_types=1);

namespace App\Security;

use App\Integrations\Plugins\PluginCatalog;
use App\Integrations\Plugins\PluginManifest;
use Fahoba\PluginApi\Dashboard\ImagePolicy;
use Fahoba\PluginApi\Plugins\LoadReport;
use Fahoba\PluginApi\Security\ContributesToCsp;
use Illuminate\Contracts\Container\Container;

/**
 * The one place that says what a page of Fahoba may load.
 *
 * Two variants of the same policy. The app (wall and phone) is static and
 * runs no inline script at all (modules/external-scripts.ts moves Nuxt's
 * into files), so `script-src 'self'` holds there; nginx sends it, written
 * by `fahoba:csp` at container start. Everything Laravel renders — the
 * admin above all — gets the second variant from SetContentSecurityPolicy:
 * Filament writes inline scripts without a nonce and Alpine evaluates its
 * expressions, so scripts there need 'unsafe-inline' and 'unsafe-eval'. The
 * rest still holds: no plugins, no foreign forms, no framing, no data sent
 * anywhere but here.
 *
 * Plugins add hosts in their composer.json (`extra.fahoba.csp`) or, for
 * hosts known only at runtime, through ContributesToCsp. The policy is built
 * each time, so a removed or failed package takes its hosts along.
 */
final class ContentSecurityPolicy implements ImagePolicy
{
    public const REPORT_PATH = '/api/csp-report';

    /** What a plugin may extend; scripts and styles never. */
    private const EXTENSIBLE = ['img-src', 'media-src', 'connect-src', 'font-src'];

    private const HOST = '#^https://(\*\.)?[a-z0-9-]+(\.[a-z0-9-]+)+(:\d{1,5})?$#';

    public function __construct(
        private readonly PluginCatalog $catalog,
        private readonly Container $container,
        private readonly LoadReport $report,
        private readonly PluginManifest $manifest,
    ) {}

    /** `off`, `report-only` (the default) or `enforce`. */
    public function mode(): string
    {
        $mode = (string) config('fahoba.csp');

        return in_array($mode, ['off', 'enforce'], true) ? $mode : 'report-only';
    }

    public function headerName(): string
    {
        return $this->mode() === 'enforce' ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only';
    }

    /** For the static app of wall and phone. */
    public function forApp(): string
    {
        return $this->render($this->directives());
    }

    /** For everything Laravel renders: Filament, mails' links, error pages. */
    public function forAdmin(): string
    {
        $directives = $this->directives();
        $directives['script-src'] = ["'self'", "'unsafe-inline'", "'unsafe-eval'"];

        return $this->render($directives);
    }

    /** Whether the browser would show an image from this address. */
    public function allowsImage(string $url): bool
    {
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return true;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! str_starts_with($url, 'https://') || ! is_string($host)) {
            return false;
        }

        $port = parse_url($url, PHP_URL_PORT);
        $origin = 'https://'.$host.(is_int($port) ? ":{$port}" : '');

        foreach ($this->directives()['img-src'] as $source) {
            if ($source === $origin || (str_starts_with($source, 'https://*.') && str_ends_with($origin, substr($source, 9)))) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, list<string>> */
    private function directives(): array
    {
        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'"],
            'style-src' => ["'self'", "'unsafe-inline'"],
            'img-src' => ["'self'", 'data:', 'blob:'],
            // Stylesheets embed small fonts as data: URIs.
            'font-src' => ["'self'", 'data:'],
            'connect-src' => ["'self'", ...$this->realtime()],
            'media-src' => ["'self'"],
            'worker-src' => ["'self'"],
            'manifest-src' => ["'self'"],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => ["'self'"],
        ];

        foreach ($this->catalog->offered('csp') as $key => $class) {
            /** @var ContributesToCsp $plugin */
            $plugin = $this->container->make($class);
            $sources = $plugin->sources();
            $problem = $this->problem($key, $sources);

            // A plugin's mistake costs its own hosts, not the whole app.
            if ($problem !== null) {
                $this->report->fail($this->catalog->packageOf('csp', $key) ?? PluginCatalog::unowned($key), $problem);

                continue;
            }

            foreach ($sources as $directive => $hosts) {
                array_push($directives[$directive], ...$hosts);
            }
        }

        // Hosts a plugin declares in its composer.json (`extra.fahoba.csp`).
        foreach ($this->manifest->packages() as $package) {
            if ($package['csp'] === [] || $this->report->failed($package['name'])) {
                continue;
            }

            $problem = $this->problem($package['name'], $package['csp']);

            if ($problem !== null) {
                $this->report->fail($package['name'], $problem);

                continue;
            }

            foreach ($package['csp'] as $directive => $hosts) {
                array_push($directives[$directive], ...$hosts);
            }
        }

        return array_map(fn (array $sources): array => array_values(array_unique($sources)), $directives);
    }

    /** @param array<string, list<string>> $sources */
    private function problem(string $key, array $sources): ?string
    {
        foreach ($sources as $directive => $hosts) {
            if (! in_array($directive, self::EXTENSIBLE, true)) {
                return "{$key} may not extend {$directive}.";
            }

            foreach ($hosts as $host) {
                if (preg_match(self::HOST, $host) !== 1) {
                    return "{$key} names {$host}; only https hosts are allowed.";
                }
            }
        }

        return null;
    }

    /**
     * The WebSocket when Reverb answers under its own host or port; under
     * the app's own address 'self' covers it already.
     *
     * @return list<string>
     */
    private function realtime(): array
    {
        $host = config('reverb.apps.apps.0.options.host');

        if (config('broadcasting.default') !== 'reverb' || ! is_string($host) || $host === '') {
            return [];
        }

        $scheme = config('reverb.apps.apps.0.options.scheme') === 'https' ? 'wss' : 'ws';
        $port = (int) config('reverb.apps.apps.0.options.port');

        return ["{$scheme}://{$host}".($port > 0 ? ":{$port}" : '')];
    }

    /** @param  array<string, list<string>>  $directives */
    private function render(array $directives): string
    {
        $parts = array_map(
            fn (string $name, array $sources): string => $name.' '.implode(' ', $sources),
            array_keys($directives),
            $directives,
        );

        return implode('; ', [...$parts, 'report-uri '.self::REPORT_PATH]);
    }
}
