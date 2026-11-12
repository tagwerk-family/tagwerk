<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\PersonalAccessToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Moves the device token from the httpOnly cookie into the Authorization
 * header.
 *
 * That keeps the token out of JavaScript's reach: the wall receives it as an
 * httpOnly cookie during pairing and sends it along automatically from then
 * on, without ever being able to read it. An attacker injecting script into
 * the page therefore cannot steal it.
 *
 * An explicitly set Authorization header wins — otherwise access with a
 * different token could no longer be tested.
 *
 * Both cookies live on `/`, so a browser that paired a wall and signed in
 * as an account sends both. The device wins — it runs before
 * `UserTokenFromCookie` — so a paired tablet stays a tablet. Unpairing or
 * signing out is the way from one to the other.
 *
 * A cookie whose token no longer exists (device deleted in the admin,
 * database reset) is cleared on the 401 it causes. Otherwise it would keep
 * winning over a fresh account sign-in, and every request after the
 * sign-in would fail without a word.
 */
final class DeviceTokenFromCookie
{
    public const COOKIE = 'fahoba_device_token';

    public static function cookie(string $token, bool $secure): Cookie
    {
        return cookie()->forever(
            self::COOKIE,
            $token,
            path: '/',
            domain: null,
            secure: $secure,
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        );
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->cookie(self::COOKIE);

        if ($request->hasHeader('Authorization') || ! is_string($token) || $token === '') {
            return $next($request);
        }

        $request->headers->set('Authorization', 'Bearer '.$token);

        $response = $next($request);

        if ($response->getStatusCode() === Response::HTTP_UNAUTHORIZED && PersonalAccessToken::findToken($token) === null) {
            $response->headers->setCookie(cookie()->forget(self::COOKIE, '/'));
        }

        return $response;
    }
}
