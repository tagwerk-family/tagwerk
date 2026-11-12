<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Photos;

/**
 * An image as the photo frame needs it.
 *
 * The ID is internal to the source: the row number for uploaded photos, the
 * encoded path within it for a folder. The URL is built not by the source
 * but by the serving route — so it always points at Fahoba itself and never
 * outside.
 */
final readonly class Photo
{
    public function __construct(
        public string $id,
        public ?string $caption = null,
        /** Day the photo was taken as an ISO date, if known. */
        public ?string $takenOn = null,
        /** Only uploaded photos carry a heart; null where a source has none. */
        public ?bool $favorite = null,
    ) {}
}
