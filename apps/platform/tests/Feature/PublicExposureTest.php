<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Household;
use App\Models\User;
use App\Services\DevicePairingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a public server shows the internet: wall, phone and API — the panels
 * only to the operator's networks.
 */
final class PublicExposureTest extends TestCase
{
    use RefreshDatabase;

    private function parent(): User
    {
        $user = User::factory()->create(['household_id' => Household::factory()->create()->id]);
        $user->forceFill(['is_operator' => true])->save();

        return $user;
    }

    public function test_without_a_list_the_panels_stay_open(): void
    {
        $this->get('/admin/login')->assertSuccessful();
    }

    public function test_the_panels_do_not_exist_outside_the_allowed_networks(): void
    {
        config(['fahoba.admin_allowed_ips' => '100.64.0.0/10, 192.0.2.7']);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);

        $this->get('/admin/login')->assertNotFound();
        $this->actingAs($this->parent())->get('/admin')->assertNotFound();
        $this->get('/auth/calendar/google/redirect')->assertNotFound();
    }

    public function test_the_panels_open_inside_the_allowed_networks(): void
    {
        config(['fahoba.admin_allowed_ips' => '100.64.0.0/10, 192.0.2.7']);

        $this->withServerVariables(['REMOTE_ADDR' => '100.101.102.103'])->get('/admin/login')->assertSuccessful();
    }

    public function test_the_api_stays_reachable_from_anywhere(): void
    {
        config(['fahoba.admin_allowed_ips' => '100.64.0.0/10']);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->postJson('/api/devices/pair', ['code' => 'NOPE1234'])
            ->assertStatus(422);
    }

    /** Behind the proxy, the client's address counts — not the proxy's. */
    public function test_the_client_address_comes_from_the_trusted_proxy(): void
    {
        config([
            'fahoba.admin_allowed_ips' => '100.64.0.0/10',
            'fahoba.trusted_proxies' => '10.0.0.2',
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->withHeader('X-Forwarded-For', '100.101.102.103')
            ->get('/admin/login')
            ->assertSuccessful();

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->withHeader('X-Forwarded-For', '203.0.113.9')
            ->get('/admin/login')
            ->assertNotFound();
    }

    /** The compose default: whatever gateway Docker picked, the proxy is trusted. */
    public function test_the_private_ranges_trust_the_proxy_on_any_docker_network(): void
    {
        config([
            'fahoba.admin_allowed_ips' => '100.64.0.0/10',
            'fahoba.trusted_proxies' => '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16',
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '172.23.0.1'])
            ->withHeader('X-Forwarded-For', '100.101.102.103')
            ->get('/admin/login')
            ->assertSuccessful();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeader('X-Forwarded-For', '100.101.102.103')
            ->get('/admin/login')
            ->assertNotFound();
    }

    /** A forged header from an untrusted client changes nothing. */
    public function test_a_forwarded_header_from_anyone_else_is_ignored(): void
    {
        config([
            'fahoba.admin_allowed_ips' => '100.64.0.0/10',
            'fahoba.trusted_proxies' => '10.0.0.2',
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeader('X-Forwarded-For', '100.101.102.103')
            ->get('/admin/login')
            ->assertNotFound();
    }

    public function test_pairing_is_throttled(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/devices/pair', ['code' => 'NOPE1234'])->assertStatus(422);
        }

        $this->postJson('/api/devices/pair', ['code' => 'NOPE1234'])->assertTooManyRequests();
    }

    public function test_successful_pairings_do_not_count_against_the_limit(): void
    {
        $household = Household::factory()->create();
        $pairing = app(DevicePairingService::class);

        for ($i = 0; $i < 12; $i++) {
            $code = $pairing->createCode($household, "Tablet {$i}")->pairing_code;
            $this->postJson('/api/devices/pair', ['code' => $code])->assertOk();
        }
    }
}
