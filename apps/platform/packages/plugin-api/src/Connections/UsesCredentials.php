<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Connections;

/**
 * Capability: the connection is made from typed-in credentials.
 *
 * The counterpart to UsesOAuth — for services that know no consent flow, only
 * an address and a secret. Both ways end the same: in a row in
 * `provider_accounts`.
 *
 * The values are stored encrypted on the account, not in the server
 * configuration. Otherwise two households could not be kept apart.
 */
interface UsesCredentials
{
    /** @return array<int, CredentialField> */
    public function credentialFields(): array;

    /**
     * Checks the credentials before they are stored.
     *
     * @param  array<string, string|null>  $credentials
     * @return string|null error message for the admin, null when all is well
     */
    public function verifyCredentials(array $credentials): ?string;

    /** What the account is called in the overview afterwards. */
    public function accountLabel(array $credentials): string;
}
