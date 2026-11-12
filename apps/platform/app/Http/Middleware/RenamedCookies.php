<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\AppToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Carries the sign-in cookies from before the rename to Fahoba over to their
 * new names. A paired tablet holds its device token forever; renaming the
 * cookie alone would have signed out every wall.
 *
 * The request sees the old value under the new name, so every reader stays
 * unaware of it. The response sets the new cookie — unless it sets or clears
 * that one itself (sign-out, a dead token) — and clears the old.
 *
 * Global and first, before `EncryptCookies` of the web group, which would
 * discard the plain old value.
 */
final class RenamedCookies
{
    private const RENAMED = [
        'tagwerk_device_token' => DeviceTokenFromCookie::COOKIE,
        'tagwerk_user_token' => UserTokenFromCookie::COOKIE,
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $carried = [];

        foreach (self::RENAMED as $old => $new) {
            $value = $request->cookies->get($old);

            if (is_string($value) && $value !== '' && ! $request->cookies->has($new)) {
                $request->cookies->set($new, $value);
                $carried[$new] = $value;
            }
        }

        $response = $next($request);

        if ($carried === []) {
            return $response;
        }

        $set = array_map(fn ($cookie): string => $cookie->getName(), $response->headers->getCookies());

        foreach (self::RENAMED as $old => $new) {
            if (! isset($carried[$new])) {
                continue;
            }

            $response->headers->setCookie(cookie()->forget($old, '/'));

            if (! in_array($new, $set, true)) {
                $response->headers->setCookie($new === DeviceTokenFromCookie::COOKIE
                    ? DeviceTokenFromCookie::cookie($carried[$new], $request->isSecure())
                    : AppToken::cookie($carried[$new]));
            }
        }

        return $response;
    }
}
