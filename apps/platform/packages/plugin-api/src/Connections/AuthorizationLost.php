<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Connections;

use RuntimeException;

/**
 * The grant is no longer valid — token revoked, password changed or simply
 * expired. The source is marked accordingly; the household has to reconnect
 * the account.
 */
final class AuthorizationLost extends RuntimeException
{
    public function __construct(public readonly ConnectedAccount $source)
    {
        parent::__construct(
            "Account {$source->providerKey()} #{$source->accountId()} needs to be connected again.",
        );
    }
}
