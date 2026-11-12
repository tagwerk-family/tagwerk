<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Middleware\DeviceTokenFromCookie;
use App\Models\Device;
use App\Services\DevicePairingService;
use App\Support\Abilities;
use App\Support\Locale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class DevicePairingController extends Controller
{
    public function __construct(private readonly DevicePairingService $pairing) {}

    /**
     * Redeems a pairing code.
     *
     * The token is deliberately NOT returned in the body, only set as an
     * httpOnly cookie. Handed to JavaScript it would inevitably end up in
     * localStorage and be readable by injected script — especially grave for
     * a permanent device token, because it never expires.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:12'],
        ]);

        $result = $this->pairing->redeem($validated['code']);
        $device = $result['device'];

        $cookie = DeviceTokenFromCookie::cookie($result['token'], $request->isSecure());

        return response()->json([
            'household_id' => $device->household_id,
            'device' => [
                'id' => $device->id,
                'name' => $device->name,
                'kind' => $device->kind->value,
            ],
        ])->withCookie($cookie);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        return response()->json([
            'id' => $device->id,
            'name' => $device->name,
            'kind' => $device->kind->value,
            'household_id' => $device->household_id,
            'locale' => Locale::for($device),
            'language' => Locale::describe(Locale::for($device)),
            'permissions' => array_map(fn ($permission): string => $permission->value, app(Abilities::class)->of($device)),
        ]);
    }

    /**
     * Unpairs this browser: its token is revoked and its cookie dropped.
     *
     * A browser holding a tablet's cookie is always the tablet; this is the
     * way out of it, to the start page and the phone's sign-in. The device
     * stays in the admin and can be paired again with a new code.
     */
    public function destroy(Request $request): JsonResponse
    {
        $device = $request->user();

        abort_unless($device instanceof Device, 404);

        $device->currentAccessToken()->delete();

        return response()->json(['unpaired' => true])
            ->withCookie(cookie()->forget(DeviceTokenFromCookie::COOKIE, '/'));
    }
}
