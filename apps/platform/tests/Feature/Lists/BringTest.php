<?php

declare(strict_types=1);

namespace Tests\Feature\Lists;

use App\Enums\ConnectionStatus;
use App\Enums\ListKind;
use App\Enums\ProviderArea;
use App\Filament\Resources\Checklists\Pages\EditChecklist;
use App\Filament\Resources\Connections\Pages\ListConnections;
use App\Jobs\MirrorListItemJob;
use App\Jobs\PushListItemJob;
use App\Jobs\SyncListJob;
use App\Models\Checklist;
use App\Models\Household;
use App\Models\ListItem;
use App\Models\ProviderAccount;
use App\Models\User;
use App\Services\DevicePairingService;
use App\Services\Lists\ListImportService;
use App\Services\Lists\ListSyncService;
use App\Support\HouseholdContext;
use Fahoba\Bring\BringListProvider;
use Fahoba\PluginApi\Connections\AuthorizationLost;
use Fahoba\PluginApi\Lists\ListItemDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Bring both ways: what Alexa adds there shows up here, what is ticked
 * anywhere is ticked everywhere — and an outage never costs the list.
 */
final class BringTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'api.getbring.com/rest/';

    private Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        app(HouseholdContext::class)->setId((int) $this->household->id);
    }

    public function test_credentials_are_stored_encrypted(): void
    {
        $account = $this->account();

        // Bypassing Eloquent: the model would silently decrypt again.
        $raw = (string) DB::table('provider_accounts')->where('id', $account->id)->value('credentials');

        $this->assertStringNotContainsString('secret', $raw);
    }

    public function test_push_logs_in_once_and_adds_to_the_list_of_that_name(): void
    {
        $this->fakeBring();
        $account = $this->account();

        $provider = app(BringListProvider::class);
        $provider->push([new ListItemDraft('Milch', '1 Liter')], $account, 'Einkauf');
        $provider->push([new ListItemDraft('Brot')], $account, 'Einkauf');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://'.self::API.'v2/bringlists/list-shop/items'
            && $request->header('X-BRING-API-KEY') !== []
            && $request->header('Authorization') === ['Bearer token-1']
            && $request['changes'][0]['itemId'] === 'Milch'
            && $request['changes'][0]['spec'] === '1 Liter'
            && $request['changes'][0]['operation'] === 'TO_PURCHASE');

        // The token is kept on the account, not fetched per call.
        $this->assertSame(1, $this->sent('v2/bringauth'));
        $this->assertSame('token-1', $account->fresh()->access_token);
    }

    public function test_an_unknown_list_name_goes_to_the_default_list(): void
    {
        $this->fakeBring();

        app(BringListProvider::class)->push([new ListItemDraft('Milch')], $this->account(), 'Einkaufsliste');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && str_ends_with($request->url(), 'v2/bringlists/list-home/items'));
    }

    public function test_a_rejected_token_gets_one_new_login(): void
    {
        $this->fakeBring(lists: Http::sequence()
            ->push('', 401)
            ->push(['lists' => [['listUuid' => 'list-home', 'name' => 'Zuhause']]]));

        $account = $this->account(['access_token' => 'stale', 'token_expires_at' => now()->addDay(), 'settings' => [
            'bring' => ['uuid' => 'user-1', 'public_uuid' => 'public-1', 'default_list' => 'list-home'],
        ]]);

        $targets = app(BringListProvider::class)->targets($account);

        $this->assertSame('list-home', $targets[0]->id);
        $this->assertSame(1, $this->sent('v2/bringauth'));
    }

    public function test_a_changed_password_asks_for_a_new_connection(): void
    {
        Http::fake([self::API.'v2/bringauth' => Http::response('', 401)]);
        $account = $this->account();

        try {
            app(BringListProvider::class)->targets($account);
            $this->fail('Expected the authorization to be lost.');
        } catch (AuthorizationLost) {
            $this->assertSame(ConnectionStatus::InvalidGrant, $account->fresh()->status);
        }
    }

    public function test_connecting_pairs_the_bring_lists_with_fahoba_lists(): void
    {
        $this->fakeBring(items: ['purchase' => [['uuid' => 'u1', 'itemId' => 'Eier', 'specification' => '']]]);

        $shopping = Checklist::factory()->create([
            'household_id' => $this->household->id,
            'name' => 'Einkaufsliste',
            'kind' => ListKind::Shopping,
            'list_provider' => null,
        ]);
        $this->item($shopping, 'Butter');
        $account = $this->account();

        app(ListImportService::class)->finish($account);

        // Bring's default list takes the shopping list the household has.
        $shopping->refresh();
        $this->assertSame('list-home', $shopping->list_target);
        $this->assertSame($account->id, $shopping->list_account_id);

        // Every other Bring list gets a list of its own.
        $this->assertSame('list-shop', Checklist::query()->where('name', 'Einkauf')->value('list_target'));

        // What was only here goes to Bring, what was only there comes here.
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && str_ends_with($request->url(), 'list-home/items')
            && $request['changes'][0]['itemId'] === 'Butter');
        $this->assertTrue($shopping->items()->where('title', 'Eier')->exists());

        // Connecting again pairs nothing twice.
        app(ListImportService::class)->finish($account->fresh());
        $this->assertSame(2, Checklist::query()->where('list_account_id', $account->id)->count());
    }

    public function test_a_bring_connection_can_be_disconnected_and_keeps_its_lists(): void
    {
        $this->actingAs(User::factory()->create(['household_id' => $this->household->id]));
        $list = $this->list();

        Livewire::test(ListConnections::class)
            ->assertTableActionVisible('delete', $list->account)
            ->callTableAction('delete', $list->account);

        $this->assertModelMissing($list->account);
        $this->assertNull($list->fresh()->list_account_id);
    }

    public function test_linking_a_list_by_hand_syncs_at_once(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create(['household_id' => $this->household->id]));
        $account = $this->account();
        $list = Checklist::factory()->create(['household_id' => $this->household->id, 'list_provider' => null]);

        Livewire::test(EditChecklist::class, ['record' => $list->getRouteKey()])
            ->fillForm([
                'list_provider' => 'fahoba/bring',
                'list_account_id' => $account->id,
                'list_target' => 'list-shop',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        Queue::assertPushed(SyncListJob::class, fn (SyncListJob $job): bool => $job->checklistId === $list->id && $job->first);

        // Saving again without touching the link does not start another.
        Livewire::test(EditChecklist::class, ['record' => $list->getRouteKey()])
            ->fillForm(['name' => 'Wocheneinkauf'])
            ->call('save');

        Queue::assertPushed(SyncListJob::class, 1);
    }

    public function test_the_first_pull_sends_what_is_open_only_here_and_reopens_it_there(): void
    {
        $this->fakeBring(items: [
            'purchase' => [['uuid' => 'u1', 'itemId' => 'Eier', 'specification' => '']],
            'recently' => [['uuid' => 'u2', 'itemId' => 'Butter', 'specification' => '']],
        ]);

        $list = $this->list();
        $butter = $this->item($list, 'Butter');
        $this->item($list, 'Eier');

        app(ListSyncService::class)->pull($list, first: true);

        // Butter was ticked at Bring long ago; linking opens it there again.
        $this->assertFalse($butter->fresh()->isDone());
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && count($request['changes']) === 1
            && $request['changes'][0]['itemId'] === 'Butter'
            && $request['changes'][0]['operation'] === 'TO_PURCHASE');
    }

    public function test_pull_brings_new_items_and_ticks_in_both_directions(): void
    {
        $this->fakeBring(items: [
            'purchase' => [
                ['uuid' => 'u1', 'itemId' => 'Äpfel', 'specification' => '1 kg'],
                ['uuid' => 'u2', 'itemId' => 'brot', 'specification' => ''],
            ],
            'recently' => [
                ['uuid' => 'u3', 'itemId' => 'Milch', 'specification' => ''],
                ['uuid' => 'u4', 'itemId' => 'Zahnpasta', 'specification' => ''],
            ],
        ]);

        $list = $this->list();
        $milk = $this->item($list, 'Milch');
        $bread = $this->item($list, 'Brot', done: true);

        app(ListSyncService::class)->pull($list);

        // Added by voice: arrives with its quantity.
        $this->assertSame('1 kg', $list->items()->where('title', 'Äpfel')->value('quantity'));

        // Ticked there, ticked here; re-added there, open again here.
        $this->assertTrue($milk->fresh()->isDone());
        $this->assertFalse($bread->fresh()->isDone());

        // Bring's history of ticked items is not imported.
        $this->assertFalse($list->items()->where('title', 'Zahnpasta')->exists());
    }

    public function test_pull_does_not_undo_a_tick_still_on_its_way(): void
    {
        $this->fakeBring(items: ['purchase' => [['uuid' => 'u1', 'itemId' => 'Milch', 'specification' => '']]]);

        $list = $this->list();
        $milk = $this->item($list, 'Milch', done: true);
        $milk->forceFill(['updated_at' => now()])->save();

        app(ListSyncService::class)->pull($list);

        $this->assertTrue($milk->fresh()->isDone());
    }

    public function test_ticking_at_the_wall_is_mirrored_with_bring_s_own_item(): void
    {
        $this->fakeBring(items: ['purchase' => [['uuid' => 'u1', 'itemId' => 'Milch', 'specification' => '2']]]);

        $list = $this->list();
        $milk = $this->item($list, 'milch');

        $this->withToken($this->wallToken())
            ->patchJson("/api/wall/items/{$milk->id}", ['done' => true])
            ->assertOk();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && $request['changes'][0]['uuid'] === 'u1'
            && $request['changes'][0]['itemId'] === 'Milch'
            && $request['changes'][0]['operation'] === 'TO_RECENTLY');
    }

    public function test_removing_and_renaming_at_the_wall_is_mirrored(): void
    {
        Queue::fake();

        $list = $this->list();
        $milk = $this->item($list, 'Milch');
        $bread = $this->item($list, 'Brot');
        $token = $this->wallToken();

        $this->withToken($token)->patchJson("/api/wall/items/{$bread->id}", ['title' => 'Vollkornbrot'])->assertOk();
        $this->withToken($token)->deleteJson("/api/wall/items/{$milk->id}")->assertNoContent();

        Queue::assertPushed(MirrorListItemJob::class, 3);
        Queue::assertNotPushed(PushListItemJob::class);
    }

    public function test_bring_outage_keeps_the_item(): void
    {
        Http::fake([self::API.'*' => Http::response('', 500)]);

        $list = $this->list();

        $this->withToken($this->wallToken())
            ->postJson("/api/wall/lists/{$list->id}/items", ['title' => 'Milch'])
            ->assertCreated();

        $this->assertSame(1, $list->items()->count());
    }

    public function test_the_schedule_only_pulls_connected_lists_of_active_households(): void
    {
        Queue::fake();

        $synced = $this->list();
        $this->list(['list_provider' => 'google']);
        $this->list(['list_account_id' => $this->account(['status' => ConnectionStatus::InvalidGrant])->id]);

        $suspended = Household::factory()->create(['suspended_at' => now()]);
        app(HouseholdContext::class)->forId((int) $suspended->id, fn () => $this->list([
            'household_id' => $suspended->id,
            'list_account_id' => $this->account(['household_id' => $suspended->id])->id,
        ]));

        $this->artisan('fahoba:sync-lists')->assertSuccessful();

        Queue::assertPushed(SyncListJob::class, 1);
        Queue::assertPushed(SyncListJob::class, fn (SyncListJob $job): bool => $job->checklistId === $synced->id);
    }

    public function test_a_failing_pull_is_logged_not_thrown(): void
    {
        Http::fake([self::API.'*' => Http::response('', 500)]);
        Log::shouldReceive('warning')->once();

        (new SyncListJob((int) $this->list()->id))->handle(app(ListSyncService::class), app(HouseholdContext::class));
    }

    /**
     * @param  array<string, mixed>|null  $items
     */
    private function fakeBring(mixed $lists = null, ?array $items = null): void
    {
        Http::fake([
            self::API.'v2/bringauth' => Http::response([
                'uuid' => 'user-1',
                'publicUuid' => 'public-1',
                'bringListUUID' => 'list-home',
                'access_token' => 'token-1',
                'expires_in' => 604799,
            ]),
            self::API.'bringusers/user-1/lists' => $lists ?? Http::response(['lists' => [
                ['listUuid' => 'list-home', 'name' => 'Zuhause'],
                ['listUuid' => 'list-shop', 'name' => 'Einkauf'],
            ]]),
            self::API.'v2/bringlists/*/items' => Http::response(''),
            self::API.'v2/bringlists/*' => Http::response(['items' => $items ?? ['purchase' => [], 'recently' => []]]),
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function account(array $attributes = []): ProviderAccount
    {
        return ProviderAccount::factory()->create([
            'household_id' => $this->household->id,
            'area' => ProviderArea::Lists,
            'provider' => 'fahoba/bring',
            'access_token' => null,
            'refresh_token' => null,
            'token_expires_at' => null,
            'credentials' => ['email' => 'familie@example.org', 'password' => 'secret'],
            ...$attributes,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function list(array $attributes = []): Checklist
    {
        return Checklist::factory()->create([
            'household_id' => $this->household->id,
            'name' => 'Einkauf',
            'list_provider' => 'fahoba/bring',
            'list_account_id' => fn (): int => (int) $this->account()->id,
            ...$attributes,
        ]);
    }

    private function item(Checklist $list, string $title, bool $done = false): ListItem
    {
        $item = $list->items()->create(['title' => $title, 'done_at' => $done ? now()->subHour() : null]);

        // Older than the grace for changes on their way out.
        $item->forceFill(['updated_at' => now()->subHour()])->saveQuietly();

        return $item;
    }

    private function wallToken(): string
    {
        $device = app(DevicePairingService::class)->createCode($this->household, 'Küche');

        return app(DevicePairingService::class)->redeem($device->pairing_code)['token'];
    }

    private function sent(string $path): int
    {
        return Http::recorded(fn (Request $request): bool => $request->url() === 'https://'.self::API.$path)->count();
    }
}
