<?php

declare(strict_types=1);

namespace Fahoba\TwoFactor;

use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Filament's authenticator-app factor, but only while the operator has the
 * extension switched on. Off, nobody is challenged and the profile offers
 * nothing to set up — a secret stored earlier stays and applies again
 * once it is switched back on.
 */
final class SwitchedAppAuthentication extends AppAuthentication
{
    public function isEnabled(Authenticatable $user): bool
    {
        return $this->active() && parent::isEnabled($user);
    }

    public function getManagementSchemaComponents(): array
    {
        return $this->active() ? parent::getManagementSchemaComponents() : [];
    }

    private function active(): bool
    {
        return app(TwoFactorExtension::class)->isActive();
    }
}
