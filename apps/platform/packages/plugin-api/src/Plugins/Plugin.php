<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Plugins;

use Closure;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Throwable;

/**
 * The service provider of a Fahoba plugin. Name it in the package's
 * `composer.json` under `extra.fahoba.provider` — Fahoba loads it itself,
 * not through Laravel's package discovery.
 *
 * Put what Laravel's `register()` and `boot()` would do into
 * `registerPlugin()` and `bootPlugin()`. Whatever they throw does not stop
 * the app: the plugin is left out, everything it offered is withdrawn, and
 * the log says why.
 */
abstract class Plugin extends ServiceProvider
{
    private ?string $package = null;

    final public function register(): void
    {
        $this->guard(fn () => $this->app->make(Catalog::class)->during($this->package(), fn () => $this->registerPlugin()));
    }

    final public function boot(): void
    {
        if (method_exists($this, 'bootPlugin')) {
            // From inside the class: `bootPlugin()` may be protected like
            // `registerPlugin()`, and still gets its dependencies injected.
            $boot = Closure::fromCallable([$this, 'bootPlugin']);
            $this->guard(fn () => $this->app->make(Catalog::class)->during($this->package(), fn () => $this->app->call($boot)));
        }
    }

    /** The Composer package this provider belongs to, e.g. `acme/waste`. */
    final public function package(): string
    {
        return $this->package ?? throw new LogicException(static::class.' was not loaded by Fahoba.');
    }

    /** @internal Called by Fahoba's loader. */
    final public function forPackage(string $package): static
    {
        $this->package = $package;

        return $this;
    }

    protected function registerPlugin(): void {}

    private function guard(callable $step): void
    {
        $report = $this->app->make(LoadReport::class);

        // Laravel's package discovery registers a provider still named in
        // `extra.laravel`; it has no package and would throw on every request.
        if ($this->package === null) {
            $report->fail(static::class, static::class.' was not loaded by Fahoba: name it in extra.fahoba.provider, not extra.laravel.');

            return;
        }

        if ($report->failed($this->package)) {
            return;
        }

        try {
            $step();
        } catch (Throwable $exception) {
            $report->fail($this->package, $exception);
        }
    }
}
