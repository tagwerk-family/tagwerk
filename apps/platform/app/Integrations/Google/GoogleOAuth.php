<?php

declare(strict_types=1);

namespace App\Integrations\Google;

use Carbon\CarbonImmutable;
use Fahoba\PluginApi\Connections\AuthorizationLost;
use Fahoba\PluginApi\Connections\ConnectedAccount;
use Illuminate\Support\Facades\Http;

/**
 * The OAuth flow with Google — shared by all Google providers.
 *
 * Client ID and secret are the same, the scopes are not: which consent is
 * requested comes with the provider. That keeps a connection for the
 * calendar separate from one for the task lists — whoever grants one has not
 * granted the other along with it.
 */
final class GoogleOAuth
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const USERINFO_URL = 'https://www.googleapis.com/oauth2/v3/userinfo';

    /**
     * @param  array<int, string>  $scopes  the scopes the provider needs
     */
    public function authorizationUrl(string $state, array $scopes, string $redirectUri): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => $this->config('client_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', $scopes),

            // Without access_type=offline there is no refresh token at all.
            'access_type' => 'offline',

            /*
             * And without prompt=consent Google sends none when the same
             * account is connected a second time — the source would be dead
             * after an hour without anything visibly going wrong.
             */
            'prompt' => 'consent',

            /*
             * Deliberately NOT include_granted_scopes: otherwise a token for
             * the task lists would carry the calendar scopes too, and the
             * separation of connections would only exist in the display.
             */
            'state' => $state,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function exchangeCode(string $code, string $redirectUri): array
    {
        return Http::asForm()
            ->post(self::TOKEN_URL, [
                'code' => $code,
                'client_id' => $this->config('client_id'),
                'client_secret' => $this->config('client_secret'),
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code',
            ])
            ->throw()
            ->json();
    }

    public function accountEmail(string $accessToken): string
    {
        $email = Http::withToken($accessToken)
            ->get(self::USERINFO_URL)
            ->throw()
            ->json('email');

        return is_string($email) ? $email : '';
    }

    /**
     * Returns a valid access token and refreshes it when needed.
     */
    public function freshAccessToken(ConnectedAccount $source): string
    {
        if (! $source->isActive()) {
            throw new AuthorizationLost($source);
        }

        if ($source->accessTokenHasExpired()) {
            $this->refresh($source);
        }

        return (string) $source->accessToken();
    }

    public function refresh(ConnectedAccount $source): void
    {
        if ($source->refreshToken() === null) {
            $this->markAuthorizationLost($source);
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'refresh_token' => $source->refreshToken(),
            'client_id' => $this->config('client_id'),
            'client_secret' => $this->config('client_secret'),
            'grant_type' => 'refresh_token',
        ]);

        /*
         * invalid_grant is the expected way a connection ends: token revoked,
         * password changed, or — for unverified apps — simply expired.
         */
        if ($response->status() === 400 && $response->json('error') === 'invalid_grant') {
            $this->markAuthorizationLost($source);
        }

        $payload = $response->throw()->json();

        $source->storeToken(
            $payload['access_token'],
            CarbonImmutable::now()->addSeconds((int) ($payload['expires_in'] ?? 3600)),
        );
    }

    /**
     * @throws AuthorizationLost
     */
    public function markAuthorizationLost(ConnectedAccount $source): never
    {
        $source->markInvalid();

        throw new AuthorizationLost($source);
    }

    private function config(string $key): string
    {
        return (string) config("services.google.{$key}");
    }
}
