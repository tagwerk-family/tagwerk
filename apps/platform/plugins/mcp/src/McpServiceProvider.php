<?php

declare(strict_types=1);

namespace Fahoba\Mcp;

use App\Http\Middleware\ApiTokenScope;
use Fahoba\PluginApi\Plugins\Catalog;
use Fahoba\PluginApi\Plugins\Plugin;
use Fahoba\PluginApi\Plugins\PluginRoutes;
use Laravel\Mcp\Facades\Mcp;

final class McpServiceProvider extends Plugin
{
    protected function registerPlugin(): void
    {
        $this->app->make(Catalog::class)->offer('areas', McpArea::KEY, McpArea::class);

        // In register: boot already loads the German texts (see example-area).
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');
    }

    public function bootPlugin(): void
    {
        $route = Mcp::web('/mcp', FahobaServer::class)
            ->middleware([...PluginRoutes::MIDDLEWARE, 'feature:'.McpArea::KEY, RequireApiToken::class]);

        // Every MCP call is a POST; the tools tell reading from writing (ApiTool).
        $route->setAction([...$route->getAction(), ApiTokenScope::SELF => true]);
    }
}
