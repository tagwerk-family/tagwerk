<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin panel only from the networks the operator names.
 *
 * On a public server this is where Google grants, Bring credentials and all
 * data about the children can be changed. Wall and phone never need it.
 * 404 instead of 403: from anywhere else, the panel does not exist.
 *
 * Empty list: open to everyone — the default in development.
 */
final class RestrictToAllowedNetworks
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = self::networks();

        abort_if($allowed !== [] && ! IpUtils::checkIp((string) $request->ip(), $allowed), 404);

        return $next($request);
    }

    /** @return array<int, string> */
    private static function networks(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('fahoba.admin_allowed_ips')),
        )));
    }
}
