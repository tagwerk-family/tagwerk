<?php

declare(strict_types=1);

namespace Fahoba\SocialLogin;

use App\Auth\AppToken;
use App\Auth\SecondFactors;
use App\Http\Controllers\Controller;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;

/**
 * There to the provider and back. The way back ends where the way there
 * started: in the app with its token cookie, or in the admin panel with the
 * web session — under the same rules as a password.
 *
 * Deliberately not behind FAHOBA_ADMIN_ALLOWED_IPS: the app's sign-in is
 * public, phones are on the move.
 */
final class SocialLoginController extends Controller
{
    private const TARGET = 'social-login.to';

    public function __construct(
        private readonly SocialLoginExtension $extension,
        private readonly SocialProviders $providers,
    ) {}

    /** The buttons on the app's sign-in screen; none while switched off. */
    public function index(): JsonResponse
    {
        $providers = $this->extension->isActive() ? $this->providers->available() : [];

        return response()->json([
            'providers' => array_map(
                fn (string $key, string $label): array => ['key' => $key, 'label' => $label],
                array_keys($providers),
                array_values($providers),
            ),
        ]);
    }

    public function redirect(Request $request, string $provider): SymfonyRedirect
    {
        abort_unless($this->extension->isActive() && $this->providers->has($provider), 404);

        $request->session()->put(self::TARGET, $request->query('to') === 'admin' ? 'admin' : 'app');

        return $this->providers->driver($provider)->redirect();
    }

    public function callback(Request $request, SocialAccounts $accounts): RedirectResponse
    {
        abort_unless($this->extension->isActive() && $this->providers->has($provider = (string) $request->route('provider')), 404);

        $admin = $request->session()->pull(self::TARGET) === 'admin';

        try {
            $remote = $this->providers->driver($provider)->user();
        } catch (\Throwable $exception) {
            // Cancelled at the provider, a stale state, an unreachable server.
            Log::info('Social sign-in failed at the provider.', ['provider' => $provider, 'error' => $exception->getMessage()]);

            return $this->fail($admin, 'failed');
        }

        $user = $accounts->resolve($provider, $remote);

        if ($user === null) {
            return $this->fail($admin, $accounts->confirmed($provider, $remote) ? 'unknown' : 'unconfirmed');
        }

        return $admin ? $this->intoAdmin($request, $user) : $this->intoApp($user);
    }

    private function intoApp(User $user): RedirectResponse
    {
        if ($user->defaultMembership() === null) {
            return $this->fail(false, 'no-household');
        }

        $factors = app(SecondFactors::class);

        // The provider replaces the password, not the second factor.
        if ($factors->required($user)) {
            return redirect()->to('/?two-factor='.$factors->pending($user));
        }

        if ($factors->missing($user)) {
            return $this->fail(false, 'factor-missing');
        }

        return redirect()->to('/')->withCookie(app(AppToken::class)->issue($user));
    }

    /** Like the mail's sign-in link: Filament's own page asks for the code. */
    private function intoAdmin(Request $request, User $user): RedirectResponse
    {
        $panel = Filament::getPanel('admin');

        if (! $user->canAccessPanel($panel)) {
            return $this->fail(true, 'unknown');
        }

        $factors = app(SecondFactors::class);

        if ($factors->required($user) || $factors->missing($user)) {
            Notification::make()
                ->title(__('Please sign in with your password and the code from your authenticator app.'))
                ->warning()
                ->send();

            return redirect()->to($panel->getLoginUrl());
        }

        Auth::guard($panel->getAuthGuard())->login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended($panel->getUrl());
    }

    /** The app shows its own sentence for the reason; the admin a notification. */
    private function fail(bool $admin, string $reason): RedirectResponse
    {
        if (! $admin) {
            return redirect()->to('/?social='.$reason);
        }

        Notification::make()
            ->title(match ($reason) {
                'unconfirmed' => __('The provider has not confirmed this email address.'),
                'failed' => __('Signing in at the provider did not work. Please try again.'),
                default => __('No account with access to the administration has this email address.'),
            })
            ->danger()
            ->send();

        return redirect()->to(Filament::getPanel('admin')->getLoginUrl());
    }
}
