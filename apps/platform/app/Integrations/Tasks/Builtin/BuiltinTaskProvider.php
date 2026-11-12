<?php

declare(strict_types=1);

namespace App\Integrations\Tasks\Builtin;

use Fahoba\PluginApi\Tasks\TaskProvider;

/**
 * Fahoba's own tasks.
 *
 * Without a single capability: these tasks come from nowhere and go nowhere.
 * That the class is almost empty is the proof that the capabilities are cut
 * right.
 */
final class BuiltinTaskProvider implements TaskProvider
{
    public const KEY = 'builtin';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Family Home Base';
    }
}
