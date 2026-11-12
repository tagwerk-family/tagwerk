<?php

declare(strict_types=1);

namespace Fahoba\SocialLogin;

use App\Integrations\Plugins\Extensions;
use Fahoba\PluginApi\Plugins\Extension;
use Fahoba\PluginApi\Plugins\ExtensionCategory;
use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Signing in with Google and other OAuth providers — for accounts that exist
 * already, matched by the address the provider confirms. Which providers
 * appear is up to the server's .env (config/social-login.php); this switch
 * decides whether any does.
 */
final class SocialLoginExtension implements Extension
{
    public const KEY = 'fahoba/social-login';

    public function __construct(
        private readonly Extensions $extensions,
        private readonly SocialProviders $providers,
    ) {}

    public function label(): string
    {
        return __('Social sign-in');
    }

    public function description(): string
    {
        return __('Sign in with Google and other providers, in the app and in the administration. Only for existing accounts, found by the confirmed email address; a second factor is still asked for. Providers are set in the server configuration.');
    }

    public function category(): ExtensionCategory
    {
        return ExtensionCategory::Security;
    }

    public function settingsSchema(): array
    {
        return [];
    }

    public function refuse(bool $enabled, array $settings, Authenticatable $by): ?string
    {
        if ($enabled && $this->providers->available() === []) {
            return __('No provider is configured yet — set its client id and secret in the server configuration first.');
        }

        return null;
    }

    public function configurePanel(Panel $panel): void
    {
        $panel->renderHook(
            PanelsRenderHook::AUTH_LOGIN_FORM_AFTER,
            fn (): string => $this->isActive()
                ? view('social-login::buttons', ['providers' => $this->providers->available()])->render()
                : '',
        );
    }

    public function isActive(): bool
    {
        return $this->extensions->enabled(self::KEY);
    }
}
