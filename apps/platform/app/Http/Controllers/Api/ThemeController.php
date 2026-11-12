<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\Backdrop;
use App\Enums\ThemePreference;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Household;
use App\Models\User;
use App\Services\ThemeSchedule;
use App\Support\Designs;
use App\Support\HouseholdContext;
use App\Support\Preferences;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The theme for whoever asks.
 *
 * One route for wall and phone: the choice lives on the authenticated model —
 * on the device for the wall, on the user for the phone.
 */
final class ThemeController extends Controller
{
    public function __construct(
        private readonly ThemeSchedule $schedule,
        private readonly HouseholdContext $context,
        private readonly Designs $designs,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->payload($this->owner($request)));
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'preference' => ['required', Rule::enum(ThemePreference::class)],
        ]);

        $owner = $this->owner($request);
        $owner->update(['theme_preference' => $data['preference']]);

        return response()->json($this->payload($owner));
    }

    /** @return array<string, mixed> */
    private function payload(Device|User $owner): array
    {
        $household = Household::query()->findOrFail($this->context->id());
        $choice = $owner->theme_preference ?? ThemePreference::Auto;

        $state = $this->schedule->state($household, $choice, CarbonImmutable::now());

        return [
            'preference' => $choice->value,
            'mode' => $household->theme_mode->value,
            'theme' => $state['theme'],
            'light_from' => $state['light_from']->toIso8601String(),
            'dark_from' => $state['dark_from']->toIso8601String(),

            // The frontend sets a timer for this instead of asking every
            // minute.
            'next_change' => $state['next_change']?->toIso8601String(),

            // Shown only by devices that chose the glass look; the device's
            // own choice before the household's.
            'backdrop' => $this->backdrop($owner, $household),

            // An installed design pack's stylesheet, null for Fahoba's own.
            'design' => $this->designs->describe($this->design($owner, $household)),
            'designs' => collect($this->designs->options())
                ->map(fn (string $name, string $key): array => ['key' => $key, 'name' => $name])
                ->values()
                ->all(),
        ];
    }

    /** The owner's choice before the household's; a removed package is Fahoba's own. */
    private function design(Device|User $owner, Household $household): ?string
    {
        $chosen = (string) ($owner->preferences['design'] ?? Preferences::HOUSEHOLD_BACKDROP);

        return match ($chosen) {
            Preferences::CORE_DESIGN => null,
            Preferences::HOUSEHOLD_BACKDROP => $household->design,
            default => $chosen,
        };
    }

    private function backdrop(Device|User $owner, Household $household): string
    {
        $chosen = Backdrop::tryFrom((string) ($owner->preferences['backdrop'] ?? ''));

        return ($chosen ?? $household->backdrop)->value;
    }

    private function owner(Request $request): Device|User
    {
        $owner = $request->user();

        abort_unless($owner instanceof Device || $owner instanceof User, 401);

        return $owner;
    }
}
