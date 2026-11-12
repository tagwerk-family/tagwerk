<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Lists;

/** A list at the provider that items can be pushed into. */
final readonly class RemoteList
{
    public function __construct(
        public string $id,
        public string $name,

        /** The list the provider opens by default, if it has one. */
        public bool $default = false,
    ) {}
}
