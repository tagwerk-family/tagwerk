<?php

declare(strict_types=1);

namespace Fahoba\Webhooks;

use Fahoba\PluginApi\Areas\Area;
use Fahoba\PluginApi\Areas\OptIn;

final class WebhooksArea implements Area, OptIn
{
    public const KEY = 'fahoba/webhooks';

    public function label(): string
    {
        return __('Webhooks');
    }

    public function shortLabel(): string
    {
        return __('Webhooks');
    }

    public function description(): string
    {
        return __('Tells other services — Home Assistant, say — what happens here.');
    }

    public function icon(): string
    {
        return 'link';
    }

    public function inMenu(): bool
    {
        return false;
    }
}
