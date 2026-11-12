<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Connections;

use Carbon\CarbonImmutable;

/**
 * One household's connection to one service in one area — what a provider
 * receives instead of a database row.
 *
 * A provider reads its credentials and settings from here and writes back
 * only through the three store methods; everything else about the
 * connection (who made it, when it last synced) belongs to Fahoba.
 *
 * No method shares a name with a column of the row behind it: Eloquent reads
 * a missing attribute through a same-named method and would loop.
 */
interface ConnectedAccount
{
    public function accountId(): int;

    public function householdId(): int;

    /** The area key, e.g. `calendar` or `lists`. */
    public function areaKey(): string;

    /** The provider key, e.g. `acme/lists`. */
    public function providerKey(): string;

    /**
     * What the household entered in the wizard (`UsesCredentials`), stored
     * encrypted.
     *
     * @return array<string, mixed>
     */
    public function credentials(): array;

    /** @return array<string, mixed> */
    public function settings(): array;

    public function accessToken(): ?string;

    public function refreshToken(): ?string;

    public function tokenExpiresAt(): ?CarbonImmutable;

    /** No token, or one that expires within the next minute. */
    public function accessTokenHasExpired(): bool;

    /** False once the service refused the connection; the household must reconnect. */
    public function isActive(): bool;

    /**
     * Replaces all settings.
     *
     * @param  array<string, mixed>  $settings
     */
    public function storeSettings(array $settings): void;

    /** A null refresh token keeps the stored one — most services send it only once. */
    public function storeToken(?string $accessToken, ?CarbonImmutable $expiresAt, ?string $refreshToken = null): void;

    /** The service refused the credentials or the consent; the household must reconnect. */
    public function markInvalid(): void;
}
