<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Households;

/**
 * Who sees a notice. `Household` reaches wall, phones and admin alike;
 * `Managers` only accounts that manage the household (who can act on it);
 * `Operator` only whoever runs the server, in the admin.
 */
enum NoticeAudience: string
{
    case Household = 'household';
    case Managers = 'managers';
    case Operator = 'operator';
}
