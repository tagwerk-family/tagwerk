<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Lists;

/** An item on its way out. */
final readonly class ListItemDraft
{
    public function __construct(
        public string $name,
        public ?string $spec = null,
    ) {}
}
