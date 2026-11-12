<?php

declare(strict_types=1);

namespace Fahoba\Bring;

use RuntimeException;

/** Bring refused the email and password; `status` says how. */
final class BringLoginFailed extends RuntimeException
{
    public function __construct(public readonly int $status)
    {
        parent::__construct("Bring refused the login ({$status}).");
    }
}
