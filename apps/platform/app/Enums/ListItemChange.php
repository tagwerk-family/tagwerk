<?php

declare(strict_types=1);

namespace App\Enums;

/** What happened to an item in Fahoba that a syncing provider should mirror. */
enum ListItemChange: string
{
    case Done = 'done';
    case Open = 'open';
    case Removed = 'removed';
}
