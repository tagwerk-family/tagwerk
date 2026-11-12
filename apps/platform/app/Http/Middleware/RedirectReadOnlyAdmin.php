<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\ReadOnlyHousehold;
use App\Support\ReadOnlyAdmin;
use Closure;
use Fahoba\PluginApi\Households\AvailableWhileReadOnly;
use Filament\Pages\Page;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin of a read-only household opens only its read-only notice, the
 * profile and pages marked `AvailableWhileReadOnly` — not every page made
 * read-only one by one: Filament has actions everywhere.
 *
 * Persistent, so Livewire's updates meet it with the page's route too.
 * Anything that is no page (logout, downloads) passes.
 */
final class RedirectReadOnlyAdmin
{
    public function __construct(private readonly ReadOnlyAdmin $readOnly) {}

    public function handle(Request $request, Closure $next): Response
    {
        $page = $request->route()?->getControllerClass();

        if ($page === null
            || ! is_subclass_of($page, Page::class)
            || is_subclass_of($page, AvailableWhileReadOnly::class)
            || $page === EditProfile::class
            || ! $this->readOnly->applies()) {
            return $next($request);
        }

        return redirect(ReadOnlyHousehold::getUrl());
    }
}
