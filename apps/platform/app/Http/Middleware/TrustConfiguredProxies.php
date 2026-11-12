<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;

/**
 * Trusts the reverse proxy named in `fahoba.trusted_proxies`.
 *
 * Read from config at request time instead of env() in bootstrap/app.php:
 * with a cached config, a value that lives only in .env would be gone there.
 */
final class TrustConfiguredProxies extends TrustProxies
{
    /** @return array<int, string>|string|null */
    protected function proxies()
    {
        $proxies = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('fahoba.trusted_proxies')),
        )));

        return $proxies === [] ? null : $proxies;
    }
}
