<?php

declare(strict_types=1);

namespace App\Integrations\Plugins;

use Fahoba\PluginApi\Plugins\LoadReport;
use Fahoba\PluginApi\Plugins\Plugin;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

/**
 * Registers every installed plugin's provider — each on its own, so one
 * that is missing, misnamed or throws is left out and the rest load.
 */
final class PluginLoader
{
    public function __construct(private readonly LoadReport $report) {}

    /** @param list<array{name: string, provider: ?string, csp?: array<string, list<string>>}> $packages */
    public function load(Application $app, array $packages): void
    {
        foreach ($packages as $package) {
            if (($package['csp'] ?? []) === PluginManifest::MALFORMED) {
                $this->report->fail($package['name'], 'extra.fahoba.csp must map directives to lists of hosts.');

                continue;
            }

            $provider = $package['provider'] ?? null;

            if ($provider === null) {
                continue;
            }

            try {
                if (! class_exists($provider)) {
                    $this->report->fail($package['name'], "Provider class {$provider} does not exist.");

                    continue;
                }

                if (! is_subclass_of($provider, Plugin::class)) {
                    $this->report->fail($package['name'], "{$provider} must extend ".Plugin::class.'.');

                    continue;
                }

                $app->register((new $provider($app))->forPackage($package['name']));
            } catch (Throwable $exception) {
                $this->report->fail($package['name'], $exception);
            }
        }
    }
}
