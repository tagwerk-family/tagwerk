<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Plugins;

use Closure;
use Illuminate\Support\Facades\Route;

/**
 * A plugin's API routes behind what the app's own routes have: both cookies
 * (wall and phone share endpoints), Sanctum, the household, and the area's
 * switch. Kept here so a plugin does not copy a middleware list that the
 * core changes later.
 */
final class PluginRoutes
{
    public const MIDDLEWARE = ['api', 'device.cookie', 'user.cookie', 'auth:sanctum', 'household'];

    /** Registers `$routes` under `/api/<area>/…`, e.g. `/api/acme/board/notes`. */
    public static function api(string $area, Closure $routes): void
    {
        Route::middleware([...self::MIDDLEWARE, 'feature:'.$area])
            ->prefix('api/'.$area)
            ->group($routes);
    }
}
