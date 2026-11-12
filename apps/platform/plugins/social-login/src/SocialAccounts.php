<?php

declare(strict_types=1);

namespace Fahoba\SocialLogin;

use App\Models\User;
use Laravel\Socialite\AbstractUser;
use Laravel\Socialite\Contracts\User as RemoteUser;

/**
 * Which account a provider's answer signs in. Only existing accounts:
 * households and accounts are still created by operator and invitation.
 */
final class SocialAccounts
{
    public function __construct(private readonly SocialProviders $providers) {}

    public function resolve(string $provider, RemoteUser $remote): ?User
    {
        $subject = (string) $remote->getId();

        $identity = SocialIdentity::query()
            ->where('provider', $provider)
            ->where('subject', $subject)
            ->first();

        if ($identity !== null) {
            return $identity->user;
        }

        $user = $this->byConfirmedAddress($provider, $remote);

        if ($user === null) {
            return null;
        }

        SocialIdentity::query()->create([
            'user_id' => $user->id,
            'provider' => $provider,
            'subject' => $subject,
            'email' => $remote->getEmail(),
        ]);

        // The invitation went to the address the provider has just confirmed.
        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return $user;
    }

    /** The first sign-in: an address the provider vouches for, nothing less. */
    private function byConfirmedAddress(string $provider, RemoteUser $remote): ?User
    {
        $email = $remote->getEmail();

        if (! is_string($email) || $email === '' || ! $this->confirmed($provider, $remote)) {
            return null;
        }

        return User::query()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->first();
    }

    /** Whether the provider says the address is confirmed. */
    public function confirmed(string $provider, RemoteUser $remote): bool
    {
        $claim = $this->providers->verifiedClaim($provider);
        $raw = $remote instanceof AbstractUser ? $remote->getRaw() : [];

        return $claim !== null && ($raw[$claim] ?? false) === true;
    }
}
