<?php

declare(strict_types=1);

namespace Fahoba\CalDav;

use RuntimeException;

/** The server refused the sign-in (401), or could not be reached at all (status 0). */
final class CalDavFailed extends RuntimeException
{
    public function __construct(public readonly int $status, string $message = '')
    {
        parent::__construct($message !== '' ? $message : "CalDAV request failed ({$status}).");
    }

    public function denied(): bool
    {
        return $this->status === 401 || $this->status === 403;
    }
}
