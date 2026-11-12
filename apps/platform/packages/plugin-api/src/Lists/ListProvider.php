<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Lists;

use Fahoba\PluginApi\Connections\ConnectedAccount;

/**
 * A service that accepts shopping items.
 *
 * One way and without a way back: the list in Fahoba stays authoritative,
 * so an outage at the provider only costs a convenience. A provider that
 * wrote back would need a contract of its own — and a decision about who
 * wins in a conflict.
 */
interface ListProvider
{
    public function key(): string;

    public function label(): string;

    /** Without credentials the provider stays selectable but does nothing. */
    public function isConfigured(): bool;

    /**
     * @param  array<int, ListItemDraft>  $items
     * @param  ConnectedAccount|null  $account  the connection to push through;
     *                                          null for services with fixed
     *                                          access from the configuration
     * @param  string|null  $target  ID or name of the list at the provider;
     *                               null takes its default
     */
    public function push(array $items, ?ConnectedAccount $account = null, ?string $target = null): void;
}
