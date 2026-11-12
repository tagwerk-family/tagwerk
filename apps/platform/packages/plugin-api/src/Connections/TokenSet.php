<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Connections;

/**
 * The result of a consent.
 *
 * `refreshToken` may be null: Google only reliably sends it on the first
 * consent, so an existing one must never be overwritten.
 */
final readonly class TokenSet
{
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken,
        public int $expiresIn,
        public string $accountLabel,
    ) {}
}
