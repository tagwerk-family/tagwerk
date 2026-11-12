<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;

/**
 * The routes a community frontend may build on: every core route under
 * `/api` plus `/broadcasting/auth`, as "METHOD uri" lines.
 *
 * Plugins' routes are theirs and stay out. `ApiContractTest` compares this
 * with `routes/api-contract.txt`: a route removed or renamed breaks
 * frontends nobody here can see, so the file changes only on purpose
 * (`php artisan fahoba:api-contract`) — add, never rename.
 */
final class ApiContract
{
    public static function path(): string
    {
        return base_path('routes/api-contract.txt');
    }

    /** @return list<string> */
    public static function routes(): array
    {
        $lines = [];

        foreach (Router::getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            $uri = $route->uri();
            $core = str_starts_with($route->getActionName(), 'App\\');

            if (! ($core && str_starts_with($uri, 'api/')) && $uri !== 'broadcasting/auth') {
                continue;
            }

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $lines[] = "{$method} /{$uri}";
            }
        }

        $lines = array_values(array_unique($lines));
        sort($lines);

        return $lines;
    }

    public static function build(): string
    {
        return "# Fahoba's HTTP contract for frontends: written by `php artisan fahoba:api-contract`.\n"
            ."# Add lines, never remove or rename one before a major release (docs/frontends.md).\n"
            .implode("\n", self::routes())."\n";
    }
}
