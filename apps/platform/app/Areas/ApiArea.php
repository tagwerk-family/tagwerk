<?php

declare(strict_types=1);

namespace App\Areas;

use Fahoba\PluginApi\Areas\Area;
use Fahoba\PluginApi\Areas\OptIn;

/**
 * Tokens for other programs (`App\Auth\ApiTokens`). Off, no token of the
 * household opens anything and the settings page is gone.
 */
final class ApiArea implements Area, OptIn
{
    public const KEY = 'api';

    public function label(): string
    {
        return __('API access');
    }

    public function shortLabel(): string
    {
        return __('API');
    }

    public function description(): string
    {
        return __('Tokens for Home Assistant, scripts and AI assistants.');
    }

    public function icon(): string
    {
        return 'key';
    }

    public function inMenu(): bool
    {
        return false;
    }
}
