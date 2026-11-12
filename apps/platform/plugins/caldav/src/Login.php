<?php

declare(strict_types=1);

namespace Fahoba\CalDav;

use Fahoba\PluginApi\Connections\ConnectedAccount;

/** Address and sign-in of one CalDAV account, from the wizard or the stored connection. */
final readonly class Login
{
    public function __construct(
        public string $url,
        public string $username,
        public string $password,
    ) {}

    /** @param array<string, mixed> $credentials */
    public static function from(array $credentials): self
    {
        return new self(
            trim((string) ($credentials['url'] ?? '')),
            trim((string) ($credentials['username'] ?? '')),
            (string) ($credentials['password'] ?? ''),
        );
    }

    public static function of(ConnectedAccount $account): self
    {
        return self::from($account->credentials());
    }
}
