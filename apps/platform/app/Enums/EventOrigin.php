<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where an event came from.
 *
 * Without this marker every notification loops forever once an event is
 * created on the tablet: we write to the provider, it reports the change
 * back, we write again.
 *
 * Only "came from outside" versus "created here" matters — which provider it
 * was is recorded on the calendar.
 */
enum EventOrigin: string
{
    case Remote = 'remote';
    case App = 'app';

    public function label(): string
    {
        return match ($this) {
            self::Remote => __('Taken from the provider'),
            self::App => __('Created in Fahoba'),
        };
    }
}
