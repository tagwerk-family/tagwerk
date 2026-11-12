<?php

declare(strict_types=1);

namespace Fahoba\IcsFeed;

use Fahoba\PluginApi\Areas\Area;
use Fahoba\PluginApi\Areas\OptIn;

/** No view of its own: a switch in the household's list, addresses in the admin. */
final class IcsFeedArea implements Area, OptIn
{
    public const KEY = 'fahoba/ics-feed';

    public function label(): string
    {
        return __('Calendar subscriptions');
    }

    public function shortLabel(): string
    {
        return __('Subscriptions');
    }

    public function description(): string
    {
        return __('Secret addresses that other calendar apps subscribe to, read-only.');
    }

    public function icon(): string
    {
        return 'calendar';
    }

    public function inMenu(): bool
    {
        return false;
    }
}
