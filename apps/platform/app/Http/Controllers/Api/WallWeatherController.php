<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Integrations\ProviderRegistry;
use App\Models\Household;
use App\Support\HouseholdContext;
use Fahoba\PluginApi\Weather\TemperatureUnit;
use Illuminate\Http\JsonResponse;

/**
 * The weather at the household's location.
 *
 * Asked with the coordinates stored for the sun position anyway. If they are
 * missing, no error comes back but a sentence saying where to fix it —
 * nobody at a wall in the hallway can do anything with an incident.
 */
final class WallWeatherController extends Controller
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly HouseholdContext $context,
    ) {}

    public function __invoke(): JsonResponse
    {
        $household = Household::query()->find($this->context->id());
        $source = $this->registry->weatherSource();

        if ($household?->latitude === null || $household->longitude === null) {
            return response()->json([
                'weather' => null,
                'hint' => __('The weather needs the household\'s place. It is set in the admin panel under “Household”.'),
            ]);
        }

        if ($source === null) {
            return response()->json(['weather' => null, 'hint' => __('No weather source set up.')]);
        }

        $weather = $source->current(
            (float) $household->latitude,
            (float) $household->longitude,
            TemperatureUnit::forTimezone($household->timezone),
        );

        return response()->json([
            'weather' => $weather?->toArray(),
            'hint' => $weather === null ? __('The weather cannot be reached right now.') : null,
            'source' => $source->label(),
        ]);
    }
}
