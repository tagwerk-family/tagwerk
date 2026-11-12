<?php

declare(strict_types=1);

namespace Fahoba\SocialLogin;

use Fahoba\PluginApi\Plugins\Catalog;
use Fahoba\PluginApi\Plugins\Plugin;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class SocialLoginServiceProvider extends Plugin
{
    protected function registerPlugin(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/social-login.php', 'social-login');

        $this->app->make(Catalog::class)->offer('extensions', SocialLoginExtension::KEY, SocialLoginExtension::class);
    }

    public function bootPlugin(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/social-login.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'social-login');

        // There and back are two requests: ten tries a minute is a person
        // retrying, not someone probing.
        RateLimiter::for('social', fn (Request $request): Limit => Limit::perMinute(20)->by((string) $request->ip()));
    }
}
