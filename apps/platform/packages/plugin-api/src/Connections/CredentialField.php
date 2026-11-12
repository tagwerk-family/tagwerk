<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Connections;

/**
 * A field a provider needs for setup.
 *
 * Deliberately without Filament: the provider describes what it needs to
 * know; how the field looks is up to the UI. Otherwise an integration would
 * be tied to the admin UI.
 */
final readonly class CredentialField
{
    public function __construct(
        public string $name,
        public string $label,
        /** Enter masked and never show again. */
        public bool $secret = false,
        public bool $required = true,
        public ?string $placeholder = null,
        public ?string $hint = null,
    ) {}
}
