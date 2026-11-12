<?php

declare(strict_types=1);

namespace Fahoba\Mcp;

use App\Auth\ApiTokens;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An assistant signs in with a token from "API access", never with the
 * app's or a tablet's: those carry every permission of their sign-in and no
 * read-only variant, and a tablet's has no account behind it at all.
 */
final class RequireApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(ApiTokens::isApiToken($request->user()?->currentAccessToken()), 403, __('Use a token from API access.'));

        return $next($request);
    }
}
