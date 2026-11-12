<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ConnectionStatus;
use App\Livewire\HouseholdNoticeBar;
use App\Models\Device;
use App\Models\Household;
use App\Models\Person;
use App\Models\ProviderAccount;
use App\Models\User;
use App\Services\HouseholdNotices;
use Fahoba\PluginApi\Households\HouseholdNotice;
use Fahoba\PluginApi\Households\Notice;
use Fahoba\PluginApi\Households\NoticeAudience;
use Fahoba\PluginApi\Households\NoticeLevel;
use Fahoba\PluginApi\Plugins\Catalog;
use Fahoba\PluginApi\Plugins\LoadReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * A plugin's word to the household reaches wall, phone, admin and the 402.
 */
final class HouseholdNoticeTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private string $wall;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        $this->wall = Device::factory()->create(['household_id' => $this->household->id])
            ->createToken('wall')->plainTextToken;
    }

    private function agenda(): TestResponse
    {
        return $this->withToken($this->wall)->getJson('/api/wall/agenda?from=2026-11-02&to=2026-11-08')->assertOk();
    }

    public function test_without_a_plugin_there_is_no_notice(): void
    {
        $this->agenda()->assertJsonPath('household.notices', []);
    }

    public function test_the_wall_and_the_phone_get_the_notice(): void
    {
        app(Catalog::class)->offer('notices', 'acme/trial', TrialNotice::class);

        $this->agenda()
            ->assertJsonPath('household.notices.0.text', 'Noch 5 Tage')
            ->assertJsonPath('household.notices.0.level', 'info');

        $parent = User::factory()->create(['household_id' => $this->household->id]);

        $this->actingAs($parent)->getJson('/api/auth/me')->assertJsonPath('notices.0.text', 'Noch 5 Tage');
    }

    public function test_every_notice_shows_the_most_severe_first(): void
    {
        app(Catalog::class)->offer('notices', 'acme/trial', TrialNotice::class);
        app(Catalog::class)->offer('notices', 'acme/pay', PayNotice::class);

        $this->agenda()
            ->assertJsonCount(2, 'household.notices')
            ->assertJsonPath('household.notices.0.level', 'blocking')
            ->assertJsonPath('household.notices.1.text', 'Noch 5 Tage');
    }

    public function test_a_managers_notice_reaches_only_managers(): void
    {
        app(Catalog::class)->offer('notices', 'acme/managers', ManagersNotice::class);
        $parent = User::factory()->create(['household_id' => $this->household->id]);
        $child = Person::factory()->child()->create(['household_id' => $this->household->id]);
        $childAccount = User::factory()->create(['household_id' => $this->household->id, 'person_id' => $child->id]);

        $this->agenda()->assertJsonPath('household.notices', []);
        $this->actingAs($parent)->getJson('/api/auth/me')->assertJsonPath('notices.0.key', 'trial-ending');
        Auth::forgetGuards();
        $this->actingAs($childAccount)->getJson('/api/auth/me')->assertJsonPath('notices', []);
    }

    public function test_an_account_closes_a_notice_for_itself(): void
    {
        app(Catalog::class)->offer('notices', 'acme/managers', ManagersNotice::class);
        $parent = User::factory()->create(['household_id' => $this->household->id]);
        $other = User::factory()->create(['household_id' => $this->household->id]);

        $this->actingAs($parent)->postJson('/api/notices/dismiss', ['key' => 'trial-ending'])->assertNoContent();
        $this->actingAs($parent)->getJson('/api/auth/me')->assertJsonPath('notices', []);

        Auth::forgetGuards();
        $this->actingAs($other)->getJson('/api/auth/me')->assertJsonPath('notices.0.key', 'trial-ending');
    }

    public function test_a_blocking_notice_cannot_be_closed(): void
    {
        $this->household->forceFill(['read_only_since' => now()])->save();
        $parent = User::factory()->create(['household_id' => $this->household->id]);

        $this->actingAs($parent)->getJson('/api/auth/me')->assertJsonPath('notices.0.dismissible', false);
        $this->actingAs($parent)->postJson('/api/notices/dismiss', ['key' => 'read-only'])->assertNotFound();
    }

    public function test_a_lost_connection_is_a_notice(): void
    {
        ProviderAccount::factory()->create([
            'household_id' => $this->household->id,
            'provider' => 'google',
            'account_label' => 'familie@example.org',
            'status' => ConnectionStatus::InvalidGrant,
        ]);

        $this->agenda()
            ->assertJsonPath('household.notices.0.level', 'warning')
            ->assertJsonPath('household.notices.0.url', '/admin/connections');
        $this->assertStringContainsString('familie@example.org', (string) $this->agenda()->json('household.notices.0.text'));
    }

    public function test_the_operator_hears_of_a_failed_plugin_in_the_admin(): void
    {
        app(LoadReport::class)->fail('acme/broken', 'class missing');
        $operator = User::factory()->create();
        $operator->forceFill(['is_operator' => true])->save();

        $notices = app(HouseholdNotices::class);
        $this->actingAs($operator);

        $this->assertStringContainsString('acme/broken', $notices->forOperator()[0]->text);
        $this->assertStringContainsString('acme/broken', HouseholdNoticeBar::notices()[0]->text);
    }

    public function test_a_throwing_notice_costs_only_itself(): void
    {
        app(Catalog::class)->offer('notices', 'acme/broken', BrokenNotice::class);
        app(Catalog::class)->offer('notices', 'acme/trial', TrialNotice::class);

        $this->agenda()->assertJsonPath('household.notices.0.text', 'Noch 5 Tage');
    }

    public function test_a_read_only_household_gets_the_core_notice(): void
    {
        $this->household->forceFill(['read_only_since' => now()])->save();

        $this->agenda()->assertJsonPath('household.notices.0.level', 'blocking');
    }

    public function test_the_refusal_carries_the_plugins_link(): void
    {
        app(Catalog::class)->offer('notices', 'acme/pay', PayNotice::class);
        $this->household->forceFill(['read_only_since' => now()])->save();

        $this->withToken($this->wall)->postJson('/api/wall/tasks', [])
            ->assertStatus(402)
            ->assertJsonPath('message', 'Jetzt freischalten')
            ->assertJsonPath('url', '/admin/billing')
            ->assertJsonPath('action', 'Bezahlen');
    }

    public function test_the_notice_is_cached_per_language(): void
    {
        app(Catalog::class)->offer('notices', 'acme/counting', CountingNotice::class);

        $this->agenda();
        $this->agenda();

        $this->assertSame(1, CountingNotice::$asked);
    }

    public function test_making_writable_clears_the_cached_notice(): void
    {
        $this->household->forceFill(['read_only_since' => now()])->save();
        $this->agenda()->assertJsonPath('household.notices.0.level', 'blocking');

        $this->household->forceFill(['read_only_since' => null])->save();
        $this->agenda()->assertJsonPath('household.notices', []);
    }

    public function test_the_admin_shows_the_notice(): void
    {
        app(Catalog::class)->offer('notices', 'acme/trial', TrialNotice::class);
        $parent = User::factory()->create(['household_id' => $this->household->id]);

        $this->actingAs($parent)->get('/admin/people')->assertSuccessful()->assertSee('Noch 5 Tage');
    }
}

final class TrialNotice implements HouseholdNotice
{
    public function notice(int $householdId): ?Notice
    {
        return new Notice('Noch 5 Tage');
    }
}

final class PayNotice implements HouseholdNotice
{
    public function notice(int $householdId): ?Notice
    {
        return new Notice('Jetzt freischalten', NoticeLevel::Blocking, '/admin/billing', 'Bezahlen');
    }
}

final class BrokenNotice implements HouseholdNotice
{
    public function notice(int $householdId): ?Notice
    {
        throw new RuntimeException('Stripe is down');
    }
}

final class CountingNotice implements HouseholdNotice
{
    public static int $asked = 0;

    public function notice(int $householdId): ?Notice
    {
        self::$asked++;

        return null;
    }
}

final class ManagersNotice implements HouseholdNotice
{
    public function notice(int $householdId): ?Notice
    {
        return new Notice('Testzeitraum endet bald', NoticeLevel::Warning, key: 'trial-ending', audience: NoticeAudience::Managers);
    }
}
