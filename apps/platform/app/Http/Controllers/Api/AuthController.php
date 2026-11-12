<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Auth\ActiveHousehold;
use App\Auth\AppToken;
use App\Auth\SecondFactors;
use App\Http\Controllers\Controller;
use App\Http\Middleware\UserTokenFromCookie;
use App\Models\Membership;
use App\Models\User;
use App\Services\HouseholdNotices;
use App\Services\LoginLinks;
use App\Services\Memberships;
use App\Support\Abilities;
use App\Support\HouseholdContext;
use App\Support\Locale;
use App\Support\OutgoingMail;
use Fahoba\PluginApi\Households\Notice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Personal login for the phone PWA.
 *
 * The token goes out as an httpOnly cookie and never comes within reach of
 * JavaScript. Same route as for the wall tablet, only with its own cookie and
 * lifetime.
 */
final class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $data['email'])->first();

        /*
         * Hash once even without a user: otherwise the API would answer
         * measurably faster for unknown addresses and reveal which ones
         * exist. A fixed comparison hash would do as well but would be a
         * magic value in the file.
         */
        if ($user === null) {
            Hash::make($data['password']);

            throw ValidationException::withMessages([
                'email' => __('Email address or password is wrong.'),
            ]);
        }

        if (! Hash::check($data['password'], (string) $user->password)) {
            throw ValidationException::withMessages([
                'email' => __('Email address or password is wrong.'),
            ]);
        }

        return $this->signIn($user);
    }

    /** Public: which sign-in ways the login screen may offer. */
    public function methods(): JsonResponse
    {
        return response()->json(['mail' => OutgoingMail::enabled()]);
    }

    /** Always the same answer: the form must not reveal which addresses exist. */
    public function requestLink(Request $request, LoginLinks $links): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        $links->issue($data['email'], 'app');

        return response()->json(status: 202);
    }

    /** The link from the mail (token) or the code typed into the app. */
    public function redeemLink(Request $request, LoginLinks $links): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required_without:code', 'nullable', 'string'],
            'code' => ['required_without:token', 'nullable', 'string'],
        ]);

        $user = $links->redeem($data['email'], $data['token'] ?? null, $data['code'] ?? null);

        if ($user === null) {
            throw ValidationException::withMessages([
                'code' => __('The code is wrong or has expired.'),
            ]);
        }

        return $this->signIn($user);
    }

    /** The code from the authenticator app, after password or link. */
    public function secondFactor(Request $request, SecondFactors $factors): JsonResponse
    {
        $data = $request->validate([
            'ticket' => ['required', 'string'],
            'code' => ['required', 'string', 'max:32'],
        ]);

        $user = $factors->redeem($data['ticket'], $data['code']);

        if ($user === null) {
            throw ValidationException::withMessages([
                'code' => __('The code is wrong or has expired.'),
            ]);
        }

        return $this->signIn($user, secondFactorPassed: true);
    }

    /**
     * Password or link were right. With a second factor the account is not
     * signed in yet: the answer is a ticket that only the code redeems.
     */
    private function signIn(User $user, bool $secondFactorPassed = false): JsonResponse
    {
        if ($user->defaultMembership() === null) {
            throw ValidationException::withMessages([
                'email' => __('This account belongs to no household.'),
            ]);
        }

        $factors = app(SecondFactors::class);

        if (! $secondFactorPassed && $factors->required($user)) {
            return response()->json(['two_factor' => $factors->pending($user)], 202);
        }

        if ($factors->missing($user)) {
            throw ValidationException::withMessages([
                'email' => __('Set up two-factor sign-in in the administration first, then sign in here.'),
            ]);
        }

        return response()
            ->json($this->userPayload($user))
            ->cookie(app(AppToken::class)->issue($user));
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json($user instanceof User ? $this->userPayload($user) : null);
    }

    /**
     * Looks at another of the account's households. The token remembers it;
     * the app reloads afterwards, so nothing of the old one stays on screen.
     */
    public function switchHousehold(Request $request): JsonResponse
    {
        $data = $request->validate(['household_id' => ['required', 'integer']]);

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $membership = app(ActiveHousehold::class)->switch($user, $request, (int) $data['household_id']);

        // Not a member there, or only invited: the same answer as for a
        // household that does not exist.
        abort_if($membership === null, 404);

        app(HouseholdContext::class)->setId((int) $membership->household_id);
        app(Abilities::class)->forget();

        return response()->json($this->userPayload($user));
    }

    /** Says yes to a household that invited this account. */
    public function acceptInvitation(Request $request, int $household): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        abort_unless(app(Memberships::class)->accept($user, $household), 404);

        return response()->json($this->userPayload($user));
    }

    /** Says no: the invitation goes away, the household sees nothing more of it. */
    public function declineInvitation(Request $request, int $household): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $membership = $user->memberships()->whereNull('accepted_at')->where('household_id', $household)->first();
        abort_if($membership === null, 404);

        app(Memberships::class)->remove($membership);

        return response()->json($this->userPayload($user));
    }

    /**
     * Leaves the household the request looks at. The last one who manages
     * it stays — a household nobody can manage is lost.
     */
    public function leaveHousehold(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $membership = $user->membership();
        abort_if($membership === null, 404);

        $memberships = app(Memberships::class);

        if ($memberships->isLastManager($membership)) {
            throw ValidationException::withMessages(['household' => __('Somebody has to keep managing the household.')]);
        }

        // The only household: leaving would delete the account, which is a
        // different decision than leaving.
        if ($user->memberships()->accepted()->count() < 2) {
            throw ValidationException::withMessages(['household' => __('This is your only household.')]);
        }

        $memberships->remove($membership);

        return response()->json(status: 204);
    }

    /** The account's own language; null follows the household. */
    public function updateLocale(Request $request): JsonResponse
    {
        $data = $request->validate([
            'locale' => ['present', 'nullable', 'string', Rule::in(array_keys(Locale::supported()))],
        ]);

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $user->update(['locale' => $data['locale']]);

        return response()->json($this->userPayload($user));
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()
            ->json(status: 204)
            ->withCookie(Cookie::forget(UserTokenFromCookie::COOKIE));
    }

    /** @return array<string, mixed> */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'person_id' => $user->currentPersonId(),
            // The households to switch between; one means there is nothing to switch.
            'households' => $user->memberships()
                ->accepted()
                ->with('household:id,name')
                ->get()
                ->map(fn (Membership $membership): array => [
                    'id' => (int) $membership->household_id,
                    'name' => (string) $membership->household?->name,
                    'current' => (int) $membership->household_id === app(HouseholdContext::class)->id(),
                ])
                ->sortBy('name')
                ->values()
                ->all(),
            // Households waiting for a yes.
            'invitations' => $user->memberships()
                ->whereNull('accepted_at')
                ->with('household:id,name')
                ->get()
                ->map(fn (Membership $membership): array => [
                    'id' => (int) $membership->household_id,
                    'name' => (string) $membership->household?->name,
                ])
                ->values()
                ->all(),
            'locale' => Locale::for($user),
            'language' => Locale::describe(Locale::for($user)),
            'locale_preference' => $user->locale,
            'locales' => Locale::supported(),
            // What the household should know (trial, read-only, a lost connection); shown until closed.
            'notices' => app(HouseholdContext::class)->hasId()
                ? array_map(
                    static fn (Notice $notice): array => $notice->toArray(),
                    app(HouseholdNotices::class)->for((int) app(HouseholdContext::class)->id(), $user),
                )
                : [],
            // What the phone shows as buttons; the server checks again.
            'permissions' => array_map(fn ($permission): string => $permission->value, app(Abilities::class)->of($user)),
        ];
    }
}
