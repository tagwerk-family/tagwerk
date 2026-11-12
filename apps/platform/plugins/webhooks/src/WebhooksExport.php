<?php

declare(strict_types=1);

namespace Fahoba\Webhooks;

use Fahoba\PluginApi\Plugins\ExportRules;
use Fahoba\PluginApi\Plugins\MovesSecrets;

/**
 * A local copy must not sign requests in the family's name. A move with its
 * passwords keeps the secret, so the receiving service still trusts it.
 */
final class WebhooksExport implements ExportRules, MovesSecrets
{
    public function movableSecrets(): array
    {
        return ['webhooks' => ['secret']];
    }

    public function systemTables(): array
    {
        return [];
    }

    public function scrubbed(): array
    {
        return ['webhooks' => ['secret']];
    }
}
