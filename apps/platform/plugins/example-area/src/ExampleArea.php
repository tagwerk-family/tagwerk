<?php

declare(strict_types=1);

namespace Fahoba\ExampleArea;

use Fahoba\PluginApi\Areas\Area;

final class ExampleArea implements Area
{
    public const KEY = 'fahoba/example-area';

    public function label(): string
    {
        return __('Example area');
    }

    public function shortLabel(): string
    {
        return __('Example');
    }

    public function description(): string
    {
        return __('Shows how a plugin brings an area of its own.');
    }

    public function icon(): string
    {
        return 'puzzle';
    }

    public function inMenu(): bool
    {
        return true;
    }
}
