<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Dashboard\DashboardDefaults;
use App\Dashboard\WidgetDefinition;
use App\Dashboard\WidgetRegistry;
use App\Dashboard\WidgetSettings;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\DashboardWidget;
use App\Models\Device;
use App\Models\Household;
use App\Models\User;
use App\Support\Abilities;
use App\Support\HouseholdContext;
use Fahoba\PluginApi\Dashboard\DataWidget;
use Fahoba\PluginApi\Dashboard\HandlesActions;
use Fahoba\PluginApi\Dashboard\HasSettings;
use Fahoba\PluginApi\Dashboard\WidgetContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The home screen made of tiles.
 *
 * The grid has 12 columns; a tile carries its position in grid cells.
 * Overlaps are not resolved but prevented: where something already sits,
 * nothing can be dropped — a grid that moves tiles around by itself on drop
 * surprises everyone watching.
 */
final class WallDashboardController extends Controller
{
    /** Columns of the grid. The row height is up to the wall. */
    public const COLUMNS = 12;

    public function __construct(
        private readonly WidgetRegistry $registry,
        private readonly DashboardDefaults $defaults,
        private readonly HouseholdContext $context,
        private readonly Abilities $abilities,
        private readonly WidgetSettings $settings,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $household = Household::query()->findOrFail($this->context->id());
        $allowed = $this->registry->forHousehold($household);
        $owner = $this->owner($request);

        $tiles = DashboardWidget::query()->ownedBy($owner)->orderBy('position')->get();

        if ($tiles->isEmpty()) {
            $tiles = collect($this->defaults->place($household, $owner));
        }

        return response()->json([
            'columns' => self::COLUMNS,
            /*
             * Tiles of disabled areas disappear from the display but stay in
             * the database — the same rule as for the areas themselves:
             * whoever turns one back on finds their old layout.
             */
            'widgets' => $tiles
                ->filter(static fn (DashboardWidget $tile): bool => isset($allowed[(string) $tile->type]))
                ->map(fn (DashboardWidget $tile): array => [
                    'id' => $tile->id,
                    'type' => $tile->type,
                    'x' => $tile->x,
                    'y' => $tile->y,
                    'w' => $tile->w,
                    'h' => $tile->h,
                    'settings' => $this->settings->shown($allowed[(string) $tile->type]->settings, $tile->settings ?? []),
                ])
                ->values(),
            'available' => collect($allowed)
                ->map(static fn (WidgetDefinition $widget): array => $widget->toArray())
                ->values(),
        ]);
    }

    /**
     * The content of a plugin tile.
     *
     * Cached for a minute per tile and settings: otherwise several tablets in
     * the house would each query the plugin's source on their own. An error
     * in the plugin is reported and stays in this tile — the wall shows a
     * sentence there instead of an empty page.
     */
    public function content(Request $request, DashboardWidget $widget): JsonResponse
    {
        $this->ensureOwn($request, $widget, arrange: false);

        [$plugin, $context] = $this->plugin($widget);
        $settings = $this->plainSettings($plugin, $widget);

        try {
            $content = Cache::remember(
                $this->contentKey($widget),
                60,
                static fn (): array => $plugin->content($context, $settings)->toArray(),
            );
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => __('Not available right now.')], 503);
        }

        return response()->json(['content' => $content]);
    }

    /**
     * A button of a plugin tile. Anyone who sees the tile may tap; a guarded
     * action asks a tablet for the parent PIN and an account for household
     * management. The answer replaces this tile's cached content; other
     * tiles of the widget catch up within their minute.
     */
    public function act(Request $request, DashboardWidget $widget, string $action): JsonResponse
    {
        $owner = $this->ensureOwn($request, $widget, arrange: false);

        [$plugin, $context] = $this->plugin($widget);

        abort_unless($plugin instanceof HandlesActions, 404);

        // A tablet asks for the PIN (423); an account needs what a parent has (403).
        if ($plugin->guarded($action)) {
            $this->abilities->authorize($owner, $owner instanceof Device ? Permission::DashboardArrange : Permission::HouseholdManage);
        }

        try {
            $content = $plugin->act($context, $this->plainSettings($plugin, $widget), $action)->toArray();
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => __('Not available right now.')], 503);
        }

        Cache::put($this->contentKey($widget), $content, 60);

        return response()->json(['content' => $content]);
    }

    public function store(Request $request): JsonResponse
    {
        $household = Household::query()->findOrFail($this->context->id());

        $data = $request->validate([
            'type' => ['required', 'string'],
            'x' => ['required', 'integer', 'min:0'],
            'y' => ['required', 'integer', 'min:0'],
            'w' => ['nullable', 'integer', 'min:1', 'max:'.self::COLUMNS],
            'h' => ['nullable', 'integer', 'min:1', 'max:24'],
            'settings' => ['nullable', 'array'],
        ]);

        $widget = $this->registry->forHousehold($household)[$data['type']] ?? null;

        if ($widget === null) {
            throw ValidationException::withMessages(['type' => __('This widget does not exist here.')]);
        }

        $owner = $this->arranging($request);

        $placement = $this->validatedPlacement(
            $owner,
            (int) $data['x'],
            (int) $data['y'],
            (int) ($data['w'] ?? $widget->w),
            (int) ($data['h'] ?? $widget->h),
        );

        $tile = DashboardWidget::create([
            'household_id' => $household->id,
            ...DashboardDefaults::owner($owner),
            'type' => $widget->key,
            // From the picker a tile comes without settings; its form follows.
            'settings' => $widget->settings === [] || ! isset($data['settings'])
                ? ($data['settings'] ?? null)
                : $this->settings->validated($widget->settings, $data['settings'], []),
            'position' => (int) DashboardWidget::query()->ownedBy($owner)->max('position') + 1,
            ...$placement,
        ]);

        return response()->json(['widget' => $this->shownTile($tile, $widget)], 201);
    }

    public function update(Request $request, DashboardWidget $widget): JsonResponse
    {
        $owner = $this->ensureOwn($request, $widget);

        $data = $request->validate([
            'x' => ['required', 'integer', 'min:0'],
            'y' => ['required', 'integer', 'min:0'],
            'w' => ['required', 'integer', 'min:1', 'max:'.self::COLUMNS],
            'h' => ['required', 'integer', 'min:1', 'max:24'],
            'settings' => ['nullable', 'array'],
        ]);

        $placement = $this->validatedPlacement(
            $owner,
            (int) $data['x'],
            (int) $data['y'],
            (int) $data['w'],
            (int) $data['h'],
            (int) $widget->id,
        );

        $definition = $this->registry->find((string) $widget->type);
        $settings = $definition === null || $definition->settings === [] || ! isset($data['settings'])
            ? ($data['settings'] ?? $widget->settings)
            : $this->settings->validated($definition->settings, $data['settings'], $widget->settings ?? []);

        $widget->update([...$placement, 'settings' => $settings]);

        return response()->json(['widget' => $this->shownTile($widget, $definition)]);
    }

    public function destroy(Request $request, DashboardWidget $widget): JsonResponse
    {
        $this->ensureOwn($request, $widget);

        $widget->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * The plugin widget behind a tile and what it may know of the household.
     *
     * @return array{DataWidget, WidgetContext}
     */
    private function plugin(DashboardWidget $widget): array
    {
        $household = Household::query()->findOrFail($this->context->id());
        $plugin = isset($this->registry->forHousehold($household)[(string) $widget->type])
            ? $this->registry->dataWidget((string) $widget->type)
            : null;

        abort_if($plugin === null, 404);

        return [$plugin, new WidgetContext(
            householdId: (int) $household->id,
            timezone: (string) ($household->timezone ?? config('app.timezone')),
            locale: app()->getLocale(),
            areas: $household->features(),
        )];
    }

    /** The tile as a device may see it: secrets only as set or not. */
    private function shownTile(DashboardWidget $tile, ?WidgetDefinition $definition): DashboardWidget
    {
        if ($definition !== null && $definition->settings !== [] && is_array($tile->settings)) {
            $tile->setAttribute('settings', $this->settings->shown($definition->settings, $tile->settings));
        }

        return $tile;
    }

    /** @return array<string, mixed> */
    private function plainSettings(DataWidget $plugin, DashboardWidget $widget): array
    {
        return $plugin instanceof HasSettings
            ? $this->settings->plain($plugin->settings(), $widget->settings ?? [])
            : $widget->settings ?? [];
    }

    /** Per tile, settings and language: a plugin may translate its texts. */
    private function contentKey(DashboardWidget $widget): string
    {
        return sprintf('widget-content:%d:%s:%s', $widget->id, md5((string) json_encode($widget->settings ?? [])), app()->getLocale());
    }

    /**
     * @return array{x: int, y: int, w: int, h: int}
     *
     * @throws ValidationException
     */
    private function validatedPlacement(Device|User $owner, int $x, int $y, int $w, int $h, ?int $except = null): array
    {
        if ($x + $w > self::COLUMNS) {
            throw ValidationException::withMessages(['x' => __('The tile sticks out beyond the grid.')]);
        }

        $occupied = DashboardWidget::query()
            ->ownedBy($owner)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except))
            ->get()
            ->contains(static fn (DashboardWidget $tile): bool => $tile->overlaps($x, $y, $w, $h));

        if ($occupied) {
            throw ValidationException::withMessages(['x' => __('There is already a tile there.')]);
        }

        return ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h];
    }

    private function owner(Request $request): Device|User
    {
        $owner = $request->user();

        abort_unless($owner instanceof Device || $owner instanceof User, 401);

        return $owner;
    }

    private function arranging(Request $request): Device|User
    {
        $owner = $this->owner($request);

        if ($owner instanceof Device) {
            $this->abilities->authorize($owner, Permission::DashboardArrange);
        }

        return $owner;
    }

    /**
     * Another dashboard's tile is not there — the household scope alone
     * would let a phone move the wall's tiles.
     */
    private function ensureOwn(Request $request, DashboardWidget $widget, bool $arrange = true): Device|User
    {
        $owner = $arrange ? $this->arranging($request) : $this->owner($request);

        abort_unless(DashboardWidget::query()->ownedBy($owner)->whereKey($widget->getKey())->exists(), 404);

        return $owner;
    }
}
