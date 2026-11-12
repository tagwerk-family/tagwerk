<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\Enums\ConnectionStatus;
use App\Enums\ProviderArea;
use App\Models\Household;
use App\Models\ProviderAccount;
use App\Support\HouseholdContext;
use Carbon\CarbonImmutable;
use Fahoba\PluginApi\Connections\ConnectedAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ConnectedAccountTest extends TestCase
{
    use RefreshDatabase;

    private ProviderAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $household = Household::factory()->create();
        app(HouseholdContext::class)->setId((int) $household->id);

        $this->account = ProviderAccount::factory()->create([
            'household_id' => $household->id,
            'area' => ProviderArea::Lists,
            'provider' => 'acme/lists',
            'credentials' => ['email' => 'a@example.org', 'password' => 'secret'],
            'settings' => ['target' => 'home'],
        ]);
    }

    public function test_a_provider_account_is_a_connected_account(): void
    {
        $account = $this->account->fresh();

        $this->assertInstanceOf(ConnectedAccount::class, $account);
        $this->assertSame((int) $account->id, $account->accountId());
        $this->assertSame((int) $account->household_id, $account->householdId());
        $this->assertSame('lists', $account->areaKey());
        $this->assertSame('acme/lists', $account->providerKey());
        $this->assertSame(['email' => 'a@example.org', 'password' => 'secret'], $account->credentials());
        $this->assertSame(['target' => 'home'], $account->settings());
        $this->assertTrue($account->isActive());
    }

    public function test_a_new_account_without_attributes_does_not_loop(): void
    {
        $account = new ProviderAccount;

        $this->assertNull($account->id);
        $this->assertNull($account->provider);
        $this->assertNull($account->settings);
        $this->assertNull($account->credentials);
    }

    public function test_storing_a_token_persists_it(): void
    {
        $at = CarbonImmutable::parse('2026-11-01 12:00:00', 'Europe/Vienna');

        $this->account->storeToken('fresh', $at);

        $fresh = $this->account->fresh();
        $this->assertSame('fresh', $fresh->accessToken());
        $this->assertTrue($fresh->tokenExpiresAt()->equalTo($at));
        $this->assertSame($this->account->refresh_token, $fresh->refreshToken());

        $this->account->storeToken('newer', $at, 'rotated');
        $this->assertSame('rotated', $this->account->fresh()->refreshToken());
    }

    public function test_storing_settings_replaces_them(): void
    {
        $this->account->storeSettings(['target' => 'office', 'bring' => ['uuid' => 'x']]);

        $this->assertSame(['target' => 'office', 'bring' => ['uuid' => 'x']], $this->account->fresh()->settings());
    }

    public function test_marking_invalid_persists_the_status(): void
    {
        $this->account->markInvalid();

        $this->assertSame(ConnectionStatus::InvalidGrant, $this->account->fresh()->status);
        $this->assertFalse($this->account->fresh()->isActive());
        $this->assertNull($this->account->fresh()->accessToken());
        $this->assertNull($this->account->fresh()->tokenExpiresAt());
    }

    public function test_an_unconfigured_account_answers_with_empty_arrays(): void
    {
        $this->account->forceFill(['credentials' => null, 'settings' => null])->save();

        $this->assertSame([], $this->account->fresh()->credentials());
        $this->assertSame([], $this->account->fresh()->settings());
    }
}
