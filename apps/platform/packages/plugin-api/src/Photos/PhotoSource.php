<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Photos;

use Fahoba\PluginApi\Connections\ConnectedAccount;

/**
 * A source of pictures.
 *
 * The account is missing for the built-in source: it belongs to the
 * household itself and is never connected — the same exception as for the
 * built-in calendar. Which household a query refers to lives in the context,
 * not in the contract.
 */
interface PhotoSource
{
    public function key(): string;

    public function label(): string;

    public function isConfigured(?ConnectedAccount $account): bool;

    /**
     * A random selection of at most $count pictures.
     *
     * @return array<int, Photo>
     */
    public function random(?ConnectedAccount $account, int $count): array;

    /**
     * The file behind an ID — null when it does not belong to this source.
     * This is where it is decided whether a guessed path serves anything.
     */
    public function file(?ConnectedAccount $account, string $id): ?PhotoFile;
}
