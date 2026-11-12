<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Tasks;

use Fahoba\PluginApi\Connections\ConnectedAccount;

/**
 * Capability: the provider accepts completions.
 *
 * Only the ticking goes out, not the task itself: whoever creates a task in
 * Fahoba wants it on the wall — and a copy at the external service would be
 * a second truth about what is still open.
 */
interface WritesTasks
{
    /** `$externalId` is the task's id at the service, as `RemoteTask` delivered it. */
    public function markDone(ConnectedAccount $account, string $externalId, bool $done): void;
}
