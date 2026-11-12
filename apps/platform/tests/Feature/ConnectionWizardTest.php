<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ProviderArea;
use App\Filament\Resources\Connections\Pages\ListConnections;
use App\Integrations\ProviderRegistry;
use App\Models\Calendar;
use App\Models\Checklist;
use App\Models\Household;
use App\Models\ProviderAccount;
use App\Models\User;
use App\Support\HouseholdContext;
use Fahoba\PluginApi\Connections\DescribesSetup;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The wizard is the only way to a new connection. If it breaks, no service
 * can be connected anymore.
 */
final class ConnectionWizardTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        $this->actingAs(User::factory()->create(['household_id' => $this->household->id]));
        app(HouseholdContext::class)->setId($this->household->id);

        config([
            'services.google.client_id' => 'client.apps.googleusercontent.com',
            'services.google.client_secret' => 'secret',
        ]);
    }

    public function test_the_header_carries_a_single_button(): void
    {
        Livewire::test(ListConnections::class)
            ->assertActionVisible('connectService')
            // There used to be one per area and provider here — and „Google
            // Tasks verbinden“ even twice.
            ->assertActionDoesNotExist('connect_tasks_google')
            ->assertActionDoesNotExist('connect_lists_google');
    }

    public function test_a_service_with_oauth_leads_to_the_provider(): void
    {
        Livewire::test(ListConnections::class)
            ->callAction(TestAction::make('connectService'), [
                'area' => ProviderArea::Tasks->value,
                'provider' => 'google',
            ])
            ->assertRedirect(route('connection.connect', [
                'area' => 'tasks',
                'provider' => 'google',
            ]));
    }

    public function test_a_service_with_credentials_is_checked_and_stored(): void
    {
        Http::fake([
            'api.getbring.com/rest/v2/bringauth' => Http::response([
                'uuid' => 'user-1',
                'publicUuid' => 'public-1',
                'bringListUUID' => 'list-home',
                'access_token' => 'token-1',
                'expires_in' => 604799,
            ]),
            'api.getbring.com/rest/bringusers/user-1/lists' => Http::response(['lists' => [
                ['listUuid' => 'list-home', 'name' => 'Zuhause'],
            ]]),
        ]);

        Livewire::test(ListConnections::class)
            ->callAction(TestAction::make('connectService'), [
                'area' => ProviderArea::Lists->value,
                'provider' => 'fahoba/bring',
                'credentials' => ['lists' => ['fahoba/bring' => [
                    'email' => 'familie@example.org',
                    'password' => 'secret',
                ]]],
            ])
            ->assertHasNoActionErrors();

        $account = ProviderAccount::query()
            ->where('area', ProviderArea::Lists)
            ->where('provider', 'fahoba/bring')
            ->sole();

        $this->assertSame('Bring! – familie@example.org', $account->account_label);
        $this->assertSame('secret', $account->credentials['password']);

        // The finisher ran: the lists to choose from are there.
        $this->assertSame([['id' => 'list-home', 'name' => 'Zuhause']], $account->settings['targets']);
    }

    public function test_wrong_credentials_create_nothing(): void
    {
        Http::fake(['api.getbring.com/*' => Http::response('', 401)]);

        Livewire::test(ListConnections::class)
            ->callAction(TestAction::make('connectService'), [
                'area' => ProviderArea::Lists->value,
                'provider' => 'fahoba/bring',
                'credentials' => ['lists' => ['fahoba/bring' => [
                    'email' => 'familie@example.org',
                    'password' => 'wrong',
                ]]],
            ]);

        $this->assertSame(0, ProviderAccount::query()->where('provider', 'fahoba/bring')->count());
    }

    /**
     * Without this the wizard would at some point show a service without icon
     * and explanation — and nobody would notice.
     */
    public function test_every_connectable_service_explains_itself(): void
    {
        $registry = app(ProviderRegistry::class);

        foreach (ProviderArea::cases() as $area) {
            foreach ($registry->connectableProviders($area) as $key => $provider) {
                $this->assertInstanceOf(
                    DescribesSetup::class,
                    $provider,
                    "{$area->value}/{$key} does not describe itself.",
                );
                $this->assertNotSame('', $provider->description());
                $this->assertStringStartsWith('heroicon-', $provider->icon());
            }
        }
    }

    public function test_the_table_names_what_each_connection_brings_in_household_time(): void
    {
        $this->household->update(['timezone' => 'Europe/Vienna']);
        $calendars = ProviderAccount::factory()->create([
            'household_id' => $this->household->id,
            'provider' => 'google',
            'synced_at' => '2026-09-29 10:00:00+00',
        ]);
        Calendar::factory()->count(2)->create(['household_id' => $this->household->id, 'provider_account_id' => $calendars->id]);
        $lists = ProviderAccount::factory()->create([
            'household_id' => $this->household->id,
            'area' => ProviderArea::Lists,
            'provider' => 'google',
        ]);
        Checklist::factory()->create(['household_id' => $this->household->id, 'list_account_id' => $lists->id]);

        $bring = ProviderAccount::factory()->create([
            'household_id' => $this->household->id,
            'area' => ProviderArea::Lists,
            'provider' => 'fahoba/bring',
            'settings' => ['targets' => [
                ['id' => 'a', 'name' => 'Zuhause'],
                ['id' => 'b', 'name' => 'Baumarkt'],
                ['id' => 'c', 'name' => 'Drogerie'],
            ]],
        ]);
        Checklist::factory()->count(2)->create(['household_id' => $this->household->id, 'list_account_id' => $bring->id]);

        Livewire::test(ListConnections::class)
            ->assertTableColumnStateSet('contents', '2 Kalender', $calendars)
            ->assertTableColumnStateSet('contents', '1 Liste', $lists)
            ->assertTableColumnStateSet('contents', '2 von 3 Listen abgeglichen', $bring)
            ->assertSee('29.09.2026 12:00');
    }
}
