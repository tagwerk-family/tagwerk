<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Photos;

/** An image file, ready to be served. */
final readonly class PhotoFile
{
    public function __construct(
        public string $path,
        public string $mime,
    ) {}
}
