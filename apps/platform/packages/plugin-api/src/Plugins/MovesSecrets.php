<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Plugins;

/**
 * Next to `ExportRules`: encrypted columns (Laravel's `encrypted` casts)
 * that still work on another server — a webhook's signing secret, a
 * service's password. When a family moves with its passwords, they travel
 * in plain text and are encrypted again with the new server's key instead
 * of being scrubbed. Anything bound to this server stays in `scrubbed()`.
 */
interface MovesSecrets
{
    /** @return array<string, list<string>> table → encrypted columns */
    public function movableSecrets(): array;
}
