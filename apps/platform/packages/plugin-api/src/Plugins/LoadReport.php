<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Plugins;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Which plugins were left out, and why.
 *
 * A broken plugin must not stop the app, but someone has to learn about it:
 * the log says `Plugin acme/x not loaded: <reason>`. The app boots on every
 * request, so the same reason is logged once an hour, not once a request.
 */
#[Singleton]
final class LoadReport
{
    /** @var array<string, string> package => first reason */
    private array $failures = [];

    public function fail(string $package, Throwable|string $reason): void
    {
        $message = $reason instanceof Throwable ? $reason->getMessage() : $reason;
        $this->failures[$package] ??= $message;

        try {
            $first = Cache::add('plugin-failed:'.$package.':'.md5($message), true, 3600);
        } catch (Throwable) {
            $first = true; // without a cache, every line rather than none
        }

        if ($first) {
            Log::error("Plugin {$package} not loaded: {$message}", $reason instanceof Throwable ? ['exception' => $reason] : []);
        }
    }

    public function failed(string $package): bool
    {
        return isset($this->failures[$package]);
    }

    /** @return array<string, string> package => reason */
    public function failures(): array
    {
        return $this->failures;
    }
}
