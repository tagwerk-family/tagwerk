<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Connections;

/**
 * Capability: the provider explains itself.
 *
 * So the choice in the setup wizard is not a list of bare names. Without this
 * capability a provider appears there with its label and a default icon — the
 * choice stays complete even when a new provider does not describe itself.
 */
interface DescribesSetup
{
    /** Name of a Heroicon, such as `heroicon-o-calendar-days`. */
    public function icon(): string;

    /** One sentence: what does this service bring? */
    public function description(): string;

    /**
     * What to know before connecting — one sentence each.
     *
     * @return array<int, string>
     */
    public function setupNotes(): array;
}
