<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Photos;

use Fahoba\PluginApi\Connections\ConnectedAccount;

/**
 * A source that can name all of its pictures along with their date.
 *
 * Rules such as "mostly from the last year" have to see the whole stock, not
 * a random handful. A source without this capability still takes part: its
 * random selection goes into the pot as it is.
 */
interface ListsPhotos
{
    /** @return array<int, Photo> */
    public function all(?ConnectedAccount $account): array;
}
