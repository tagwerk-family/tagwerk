<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Connections;

/**
 * Capability: the connection is made through a consent at the provider.
 *
 * Applies to every area — a list service with consent behaves no differently
 * from a calendar here.
 *
 * Only providers with this capability appear in the admin under "connect"
 * and show a connection state on the wall.
 */
interface UsesOAuth
{
    /**
     * Which scopes the consent covers.
     *
     * Separate per area: whoever grants the calendar has not granted their
     * task lists along with it.
     *
     * @return array<int, string>
     */
    public function scopes(): array;

    /**
     * @param  string  $redirectUri  where the provider sends back to — a
     *                               different address per area, so a calendar
     *                               consent does not end up in the list flow
     */
    public function authorizationUrl(string $state, string $redirectUri): string;

    /**
     * @param  string  $redirectUri  the same address as on the way out;
     *                               Google rejects the exchange otherwise
     */
    public function exchangeCode(string $code, string $redirectUri): TokenSet;

    /**
     * A valid access token, refreshed when needed.
     *
     * @throws AuthorizationLost when the consent no longer holds
     */
    public function freshAccessToken(ConnectedAccount $account): string;

    /**
     * Records the lost consent on the connection and aborts.
     *
     * @throws AuthorizationLost always
     */
    public function markAuthorizationLost(ConnectedAccount $account): never;
}
