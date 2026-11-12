<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Lists;

/** An item as the provider holds it; `done` means ticked off there. */
final readonly class RemoteListItem
{
    public function __construct(
        public string $name,
        public ?string $spec = null,
        public bool $done = false,
    ) {}
}
