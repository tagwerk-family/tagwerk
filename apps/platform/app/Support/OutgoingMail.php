<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Whether this server sends mail at all (`FAHOBA_MAIL`).
 */
final class OutgoingMail
{
    public static function enabled(): bool
    {
        return config('fahoba.mail') !== 'off';
    }
}
