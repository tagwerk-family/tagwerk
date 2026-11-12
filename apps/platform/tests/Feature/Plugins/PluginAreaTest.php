<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\Areas\ApiArea;
use App\Areas\Areas;
use App\Enums\Feature;
use App\Models\Device;
use App\Models\Household;
use App\Support\HouseholdContext;
use Fahoba\PluginApi\Areas\Area;
use Fahoba\PluginApi\Plugins\Catalog;
use Fahoba\PluginApi\Plugins\PluginRoutes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * A plugin's area behaves like a built-in one: on until switched off, gone
 * from menu and API when off, and only in the menu if it has a view.
 */
final class PluginAreaTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        app(Catalog::class)->offer('areas', 'acme/board', BoardArea::class);
        app(Catalog::class)->offer('areas', 'acme/hooks', HooksArea::class);

        PluginRoutes::api('acme/board', function (): void {
            Route::get('/notes', fn (): array => ['ok' => true]);
        });

        $this->household = Household::factory()->create();
        $device = Device::factory()->create(['household_id' => $this->household->id]);
        $this->token = $device->createToken('wall', ['device'])->plainTextToken;
    }

    public function test_an_installed_area_is_on_for_every_household(): void
    {
        $this->assertTrue($this->household->has('acme/board'));
        $this->assertContains('acme/board', $this->household->features());
        $this->assertContains('acme/hooks', $this->household->features());
    }

    public function test_plugin_areas_follow_the_built_in_ones(): void
    {
        $keys = app(Areas::class)->keys();

        $builtIn = array_map(static fn (Feature $feature): string => $feature->value, Feature::cases());

        $this->assertSame([...$builtIn, ApiArea::KEY], array_slice($keys, 0, count($builtIn) + 1));
        $plugins = array_slice($keys, count($builtIn) + 1);
        $this->assertContains('acme/board', $plugins);
        $this->assertSame($plugins, collect($plugins)->sort()->values()->all());
    }

    public function test_switched_off_closes_its_routes(): void
    {
        $this->withToken($this->token)->getJson('/api/acme/board/notes')->assertOk();

        $this->household->update(['disabled_features' => ['acme/board']]);

        $this->withToken($this->token)->getJson('/api/acme/board/notes')->assertNotFound();
        $this->assertNotContains('acme/board', $this->household->fresh()->features());
    }

    public function test_only_areas_with_a_view_reach_the_menu(): void
    {
        $areas = $this->withToken($this->token)
            ->getJson('/api/wall/agenda?from=2026-09-14&to=2026-09-20')
            ->assertOk()
            ->json('household.areas');

        $board = collect($areas)->firstWhere('feature', 'acme/board');

        $this->assertSame(['feature' => 'acme/board', 'mode' => 'acme/board', 'label' => 'Board', 'icon' => 'lists'], $board);
        $this->assertNull(collect($areas)->firstWhere('feature', 'acme/hooks'));
    }

    public function test_a_plugin_area_can_be_the_start_area(): void
    {
        $this->withToken($this->token)
            ->putJson('/api/preferences', ['start_area' => 'acme/board'])
            ->assertOk()
            ->assertJsonPath('preferences.start_area', 'acme/board');

        $this->withToken($this->token)
            ->putJson('/api/preferences', ['start_area' => 'acme/hooks'])
            ->assertUnprocessable();
    }

    public function test_a_removed_package_leaves_nothing_behind(): void
    {
        $this->household->update(['disabled_features' => ['gone/plugin']]);

        $this->assertFalse($this->household->fresh()->has('gone/plugin'));
        $this->assertNotContains('gone/plugin', $this->household->fresh()->features());
        $this->assertTrue($this->household->fresh()->has('acme/board'));
    }

    public function test_an_unknown_area_counts_as_off(): void
    {
        app(HouseholdContext::class)->setId((int) $this->household->id);

        $this->assertFalse($this->household->has('nobody/installed'));
    }
}

final class BoardArea implements Area
{
    public function label(): string
    {
        return 'Pin board';
    }

    public function shortLabel(): string
    {
        return 'Board';
    }

    public function description(): string
    {
        return 'Notes for everyone.';
    }

    public function icon(): string
    {
        return 'lists';
    }

    public function inMenu(): bool
    {
        return true;
    }
}

final class HooksArea implements Area
{
    public function label(): string
    {
        return 'Hooks';
    }

    public function shortLabel(): string
    {
        return 'Hooks';
    }

    public function description(): string
    {
        return 'Tells other services.';
    }

    public function icon(): string
    {
        return 'link';
    }

    public function inMenu(): bool
    {
        return false;
    }
}
