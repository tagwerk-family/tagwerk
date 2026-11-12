<?php

declare(strict_types=1);

namespace App\Integrations\Calendar\Builtin;

use Fahoba\PluginApi\Calendar\CalendarProvider;

/**
 * The calendar without an external service — the foundation.
 *
 * It brings no capability, and that is exactly its nature: events are
 * created on the wall, live in Fahoba and are synced nowhere. There is
 * nothing to sign in to, nothing to fetch, nothing to notify.
 *
 * That there is hardly any code here is not a flaw but the proof that the
 * capabilities are cut right: if just one of them were mandatory, there
 * would be a dummy here.
 */
final class BuiltinCalendarProvider implements CalendarProvider
{
    public const KEY = 'builtin';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Fahoba';
    }
}
