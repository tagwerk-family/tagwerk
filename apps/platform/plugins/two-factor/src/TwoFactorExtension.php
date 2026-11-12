<?php

declare(strict_types=1);

namespace Fahoba\TwoFactor;

use App\Auth\Contracts\SecondFactor;
use App\Integrations\Plugins\Extensions;
use App\Models\User;
use Fahoba\PluginApi\Plugins\Extension;
use Fahoba\PluginApi\Plugins\ExtensionCategory;
use Filament\Forms\Components\Radio;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A code from an authenticator app after the password — in the admin by
 * Filament's own challenge, on the phone and for login links through
 * App\Auth\SecondFactors. Eight recovery codes for a lost phone.
 *
 * Who must use it is the operator's choice; everyone else may. Setting it
 * up happens in the admin profile, which is why only accounts that reach
 * the admin can be required to.
 */
final class TwoFactorExtension implements Extension, SecondFactor
{
    public const KEY = 'fahoba/two-factor';

    public function __construct(private readonly Extensions $extensions) {}

    public function label(): string
    {
        return __('Two-factor sign-in');
    }

    public function description(): string
    {
        return __('After the password, a code from an authenticator app — in the administration and on the phone. Recovery codes help when the phone is lost. Set up in the own profile.');
    }

    public function category(): ExtensionCategory
    {
        return ExtensionCategory::Security;
    }

    public function settingsSchema(): array
    {
        return [
            Radio::make('required_for')
                ->label(__('Required for'))
                ->options([
                    'nobody' => __('Nobody — everyone may set it up'),
                    'operators' => __('Operators'),
                    'managers' => __('Operators and everyone with administration rights'),
                ])
                ->default('nobody'),
        ];
    }

    public function refuse(bool $enabled, array $settings, Authenticatable $by): ?string
    {
        if (! $enabled || ($settings['required_for'] ?? 'nobody') === 'nobody' || ($by instanceof User && filled($by->getAppAuthenticationSecret()))) {
            return null;
        }

        return __('Set up two-factor sign-in in your own profile first — otherwise the requirement would lock you out.');
    }

    public function configurePanel(Panel $panel): void
    {
        $panel
            ->multiFactorAuthentication([
                SwitchedAppAuthentication::make()->recoverable()->brandName(config('app.name')),
            ], isRequired: true)
            // "Required" is decided per account and request, not by the panel.
            ->multiFactorAuthenticationRequiredMiddlewareName(RequireSecondFactor::class);
    }

    public function isActive(): bool
    {
        return $this->extensions->enabled(self::KEY);
    }

    public function isSetUp(User $user): bool
    {
        return $this->isActive() && filled($user->getAppAuthenticationSecret());
    }

    public function isMissing(User $user): bool
    {
        if (! $this->isActive() || filled($user->getAppAuthenticationSecret())) {
            return false;
        }

        return match ($this->extensions->settings(self::KEY)['required_for'] ?? 'nobody') {
            'operators' => $user->is_operator === true,
            'managers' => $user->is_operator === true
                || $user->managesAnyHousehold(),
            default => false,
        };
    }

    public function verify(User $user, #[\SensitiveParameter] string $code): bool
    {
        $app = SwitchedAppAuthentication::make();
        $code = trim($code);

        return $app->verifyCode($code, $user->getAppAuthenticationSecret(), shouldPreventCodeReuse: true)
            || (filled($user->getAppAuthenticationRecoveryCodes()) && $app->verifyRecoveryCode($code, $user));
    }
}
