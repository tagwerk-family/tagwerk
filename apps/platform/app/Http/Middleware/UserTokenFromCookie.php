<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The same as {@see DeviceTokenFromCookie}, but for the phone PWA's personal
 * login.
 *
 * Deliberately a class and cookie name of its own: a device token lasts
 * forever and belongs to a device, a user token belongs to a person and is
 * thrown away on logout. A shared cookie would sign the wall tablet out
 * together with the phone.
 */
final class UserTokenFromCookie
{
    public const COOKIE = 'fahoba_user_token';

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->cookie(self::COOKIE);

        if (! $request->hasHeader('Authorization') && is_string($token) && $token !== '') {
            $request->headers->set('Authorization', 'Bearer '.$token);
        }

        return $next($request);
    }
}
