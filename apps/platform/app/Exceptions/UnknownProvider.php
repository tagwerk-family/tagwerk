<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The database names a provider that config/fahoba.php does not know.
 *
 * Happens when a provider was removed while its sources still exist.
 * Deliberately loud: silently falling back to another provider would write
 * events to the wrong place.
 */
final class UnknownProvider extends RuntimeException
{
    public function __construct(string $family, string $key)
    {
        parent::__construct("No {$family} provider registered under the key {$key}.");
    }
}
