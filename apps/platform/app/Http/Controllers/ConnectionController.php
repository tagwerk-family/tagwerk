<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ConnectionStatus;
use App\Enums\ProviderArea;
use App\Exceptions\UnknownProvider;
use App\Filament\Resources\Calendars\CalendarResource;
use App\Integrations\ProviderRegistry;
use App\Models\ProviderAccount;
use App\Support\HouseholdContext;
use Fahoba\PluginApi\Connections\FinishesConnection;
use Fahoba\PluginApi\Connections\UsesOAuth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Receives authorizations — the same route for every area.
 *
 * The area is part of the address because an authorization for the calendar
 * is a different one from one for the lists: different scopes, its own row,
 * disconnected separately. The same service can show up here twice without
 * one bringing the other along.
 */
final class ConnectionController extends Controller
{
    private const STATE_KEY = 'connection_oauth_state';

    public function __construct(private readonly ProviderRegistry $registry) {}

    public function redirect(Request $request, ProviderArea $area, string $provider): RedirectResponse
    {
        $oauth = $this->oauthProvider($area, $provider);
        $state = Str::random(40);

        /*
         * Area and provider travel along in the session: otherwise a callback
         * from the calendar route could be bent into the list route, and a
         * calendar token would end up as a list connection in the table.
         */
        $request->session()->put(self::STATE_KEY, [
            'state' => $state,
            'area' => $area->value,
            'provider' => $provider,
        ]);

        return redirect()->away($oauth->authorizationUrl($state, self::redirectUri($area, $provider)));
    }

    public function callback(Request $request, ProviderArea $area, string $provider): RedirectResponse
    {
        $oauth = $this->oauthProvider($area, $provider);
        $expected = $request->session()->pull(self::STATE_KEY);

        // Protection against forged callbacks.
        if (! is_array($expected)
            || ! is_string($expected['state'] ?? null)
            || ! hash_equals($expected['state'], (string) $request->query('state'))
            || $expected['area'] !== $area->value
            || $expected['provider'] !== $provider
        ) {
            return redirect('/admin')
                ->with('error', __('The connection could not be matched. Please try again.'));
        }

        if ($request->query('error') !== null) {
            return redirect('/admin')
                ->with('error', __('The approval was cancelled.'));
        }

        $tokens = $oauth->exchangeCode(
            (string) $request->query('code'),
            self::redirectUri($area, $provider),
        );

        $attributes = [
            'user_id' => $request->user()?->id,
            'access_token' => $tokens->accessToken,
            'token_expires_at' => now()->addSeconds($tokens->expiresIn),
            'status' => ConnectionStatus::Active,
        ];

        /*
         * A refresh token does not come with every authorization. An existing
         * one must therefore never be overwritten with null.
         */
        if ($tokens->refreshToken !== null) {
            $attributes['refresh_token'] = $tokens->refreshToken;
        }

        $account = ProviderAccount::updateOrCreate(
            [
                'household_id' => app(HouseholdContext::class)->id(),
                'area' => $area,
                'provider' => $provider,
                'account_label' => $tokens->accountLabel,
            ],
            $attributes,
        );

        $finisherClass = $area->finisher();

        if ($finisherClass !== null) {
            /** @var FinishesConnection $finisher */
            $finisher = app($finisherClass);
            $finisher->finish($account);
        }

        return redirect($this->destination($area))
            ->with('status', "{$area->label()} — {$oauth->label()}: {$tokens->accountLabel} verbunden.");
    }

    /**
     * Where to go after the authorization.
     *
     * The calendar still needs one more step: who do the freshly imported
     * calendars belong to? Whoever does not find the menu item on their own
     * ends up with colourless family calendars on the wall. That is why this
     * route ends in the calendar list and not on the dashboard.
     */
    private function destination(ProviderArea $area): string
    {
        return $area === ProviderArea::Calendar
            ? CalendarResource::getUrl('index')
            : '/admin';
    }

    /**
     * A provider without an authorization flow has no connection route — the
     * built-in calendar, for instance, comes with the household. The address
     * is guessable, hence 404 instead of an incident in the log.
     */
    private function oauthProvider(ProviderArea $area, string $provider): UsesOAuth
    {
        try {
            return $this->registry->oauthProvider($area, $provider);
        } catch (UnknownProvider) {
            abort(404);
        }
    }

    /**
     * From the route rather than the configuration: each area has a
     * different address, and a single setting could only ever be one of them.
     */
    private static function redirectUri(ProviderArea $area, string $provider): string
    {
        return route('connection.callback', [
            'area' => $area->value,
            'provider' => $provider,
        ]);
    }
}
