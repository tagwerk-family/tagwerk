<?php

declare(strict_types=1);

namespace Fahoba\OpenApi;

use Dedoc\Scramble\Scramble;
use Fahoba\PluginApi\Plugins\Plugin;
use Illuminate\Routing\Route;

/**
 * `GET /api/openapi.json`: the description of every `/api` route, generated
 * from the routes and their validation by Scramble.
 *
 * Public on purpose — the description is no secret, the data behind it is
 * (API tokens, Settings → API access). Scramble's own pages under `/docs`
 * stay off: they load a viewer from a CDN the CSP does not allow.
 */
final class OpenApiServiceProvider extends Plugin
{
    public const PATH = 'api/openapi.json';

    public function bootPlugin(): void
    {
        Scramble::ignoreDefaultRoutes();

        // On the configuration object: Scramble read its config file before this provider booted.
        Scramble::configure()
            ->useConfig([
                ...(array) config('scramble'),
                'api_path' => 'api',
                'info' => [
                    'version' => (string) config('app.version', '1'),
                    'description' => 'Family Home Base API. Authenticate with an API token (Settings → API access) as Bearer token; '
                        .'read-only tokens may only send GET requests.',
                ],
                'middleware' => ['throttle:30,1'],
            ])
            ->routes(static fn (Route $route): bool => str_starts_with($route->uri(), 'api/') && $route->uri() !== self::PATH);

        Scramble::registerJsonSpecificationRoute(self::PATH);
    }
}
