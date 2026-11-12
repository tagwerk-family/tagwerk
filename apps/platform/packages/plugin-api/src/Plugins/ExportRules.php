<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Plugins;

/**
 * What the household export does with a plugin's tables:
 * `offer('export', 'vendor/name', Class)`.
 *
 * Tables with a `household_id` travel along by themselves. A table without
 * one stops the export unless it is named here, and a secret in a scoped
 * table would reach a local copy unless it is scrubbed.
 */
interface ExportRules
{
    /** @return list<string> server tables that stay out of every export */
    public function systemTables(): array;

    /** @return array<string, list<string>> table → columns cleared on the way out */
    public function scrubbed(): array;
}
