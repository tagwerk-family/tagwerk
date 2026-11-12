<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Lists;

use Fahoba\PluginApi\Connections\ConnectedAccount;

/**
 * Capability: items travel both ways, not only out.
 *
 * Items are matched by name. A service that is filled by voice knows no
 * other key, and two open "Milch" on one shopping list are one too many
 * anyway.
 */
interface SyncsItems
{
    /** @return array<int, RemoteListItem> */
    public function items(ConnectedAccount $account, string $target): array;

    public function markDone(ConnectedAccount $account, string $target, ListItemDraft $item, bool $done): void;

    public function remove(ConnectedAccount $account, string $target, ListItemDraft $item): void;
}
