<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\Models\DashboardWidget;
use App\Models\Household;
use App\Services\DevicePairingService;
use App\Support\HouseholdContext;
use Fahoba\ExampleArea\ExampleCounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The example plugin's tile: settings and buttons through the API only. */
final class ExampleCounterTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_counts_by_its_step_and_a_reset_needs_the_pin(): void
    {
        $household = Household::factory()->create();
        app(HouseholdContext::class)->setId((int) $household->id);
        $code = app(DevicePairingService::class)->createCode($household, 'Küche');
        ['device' => $device, 'token' => $token] = app(DevicePairingService::class)->redeem($code->pairing_code);
        $this->parentWithPin($household);

        $tile = DashboardWidget::factory()->create([
            'household_id' => $household->id, 'device_id' => $device->id, 'type' => ExampleCounter::KEY,
            'x' => 0, 'y' => 0, 'w' => 4, 'h' => 3, 'settings' => ['name' => 'Becher', 'step' => '5'],
        ]);

        $this->withToken($token)->postJson("/api/wall/dashboard/{$tile->id}/action/add")
            ->assertOk()
            ->assertJsonPath('content.caption', 'Becher: 5')
            ->assertJsonPath('content.buttons.0.label', '5 dazu');

        $this->withToken($token)->postJson("/api/wall/dashboard/{$tile->id}/action/reset")->assertStatus(423);
    }
}
