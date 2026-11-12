<?php

declare(strict_types=1);

namespace Fahoba\TwoFactor;

use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends an account that must have a second factor but has none to
 * Filament's set-up page — on every admin page, however it signed in.
 */
final class RequireSecondFactor
{
    public function __construct(private readonly TwoFactorExtension $extension) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Filament::auth()->user();

        if ($user instanceof User && $this->extension->isMissing($user)) {
            return redirect()->guest(Filament::getSetUpRequiredMultiFactorAuthenticationUrl());
        }

        return $next($request);
    }
}
