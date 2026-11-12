<?php

declare(strict_types=1);

namespace Fahoba\SocialLogin;

use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;

/** The providers from config/social-login.php that have their credentials. */
final class SocialProviders
{
    /** @return array<string, string> key → label */
    public function available(): array
    {
        $available = [];

        foreach ((array) config('social-login.providers') as $key => $provider) {
            if (filled($provider['client_id'] ?? null) && filled($provider['client_secret'] ?? null)) {
                $available[(string) $key] = (string) $provider['label'];
            }
        }

        return $available;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->available());
    }

    public function driver(string $key): Provider
    {
        $config = config("social-login.providers.{$key}");

        // Built from our own config: `services.google` belongs to the
        // calendar connection, with other scopes and another redirect.
        return Socialite::buildProvider($config['driver'], [
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'redirect' => route('social.callback', $key),
        ]);
    }

    public function verifiedClaim(string $key): ?string
    {
        $claim = config("social-login.providers.{$key}.verified_claim");

        return is_string($claim) ? $claim : null;
    }
}
