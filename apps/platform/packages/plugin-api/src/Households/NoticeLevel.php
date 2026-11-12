<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Households;

/**
 * How loudly a notice speaks. `Blocking` means the household cannot write
 * until someone acts — the read-only state, say.
 */
enum NoticeLevel: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Blocking = 'blocking';

    public function weight(): int
    {
        return match ($this) {
            self::Info => 0,
            self::Warning => 1,
            self::Blocking => 2,
        };
    }
}
