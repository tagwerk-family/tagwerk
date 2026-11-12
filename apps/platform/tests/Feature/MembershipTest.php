<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\ActiveHousehold;
use App\Enums\DeviceKind;
use App\Enums\Permission;
use App\Enums\PersonRole;
use App\Models\Checklist;
use App\Models\Device;
use App\Models\Household;
use App\Models\Membership;
use App\Models\Person;
use App\Models\User;
use App\Notifications\HouseholdInvitation;
use App\Notifications\Invitation;
use App\Services\DevicePairingService;
use App\Services\HouseholdOnboarding;
use App\Services\Memberships;
use App\Services\ParentPin;
use App\Services\Push\WebPushService;
use App\Support\HouseholdContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

/**
 * One account, several households: grandma signs in once, sees each family
 * apart and may do in each only what that family allows.
 */
final class MembershipTest extends TestCase
{
    use RefreshDatabase;

    private Household $huber;

    private Household $mueller;

    private User $grandma;

    protected function setUp(): void
    {
        parent::setUp();

        $this->huber = Household::factory()->create(['name' => 'Huber']);
        $this->mueller = Household::factory()->create(['name' => 'Müller']);

        // A guest at the Hubers, a parent who manages at the Müllers.
        $this->grandma = User::factory()->create([
            'household_id' => $this->huber->id,
            'person_id' => Person::factory()->guest()->create(['household_id' => $this->huber->id, 'name' => 'Oma'])->id,
        ]);
        $this->grandma->memberships()->create([
            'household_id' => $this->mueller->id,
            'person_id' => Person::factory()->create(['household_id' => $this->mueller->id, 'name' => 'Oma'])->id,
            'accepted_at' => now(),
        ]);

        Checklist::factory()->create(['household_id' => $this->huber->id, 'name' => 'Huber-Einkauf']);
        Checklist::factory()->create(['household_id' => $this->mueller->id, 'name' => 'Müller-Einkauf']);
    }

    private function phone(): string
    {
        return $this->grandma->createToken('phone')->plainTextToken;
    }

    /** @return list<string> */
    private function lists(string $token): array
    {
        Auth::forgetGuards();

        return array_column((array) $this->withToken($token)->getJson('/api/wall/lists')->assertOk()->json('lists'), 'name');
    }

    /** Where an account belongs is its memberships; the account itself names no household. */
    public function test_an_account_names_no_household_of_its_own(): void
    {
        $this->assertFalse(Schema::hasColumn('users', 'household_id'));
        $this->assertFalse(Schema::hasColumn('users', 'person_id'));

        $user = app(HouseholdOnboarding::class)->create('Familie Neu', 'Europe/Vienna', 'Eva', 'eva@example.test', 'geheim-geheim');

        $membership = $user->memberships()->sole();
        $this->assertNotNull($membership->accepted_at);
        $this->assertSame('Eva', $membership->person?->name);
    }

    public function test_the_phone_sees_one_household_and_switches_to_the_other(): void
    {
        $token = $this->phone();

        $first = $this->lists($token);
        $this->assertCount(1, $first);

        $other = $first === ['Huber-Einkauf'] ? $this->mueller : $this->huber;

        $this->withToken($token)
            ->putJson('/api/auth/household', ['household_id' => $other->id])
            ->assertOk()
            ->assertJsonCount(2, 'households');

        $this->assertNotSame($first, $this->lists($token));
    }

    public function test_two_phones_look_at_different_households(): void
    {
        $laptop = $this->phone();
        $phone = $this->phone();

        $this->withToken($laptop)->putJson('/api/auth/household', ['household_id' => $this->huber->id])->assertOk();
        Auth::forgetGuards();
        $this->withToken($phone)->putJson('/api/auth/household', ['household_id' => $this->mueller->id])->assertOk();

        $this->assertSame(['Huber-Einkauf'], $this->lists($laptop));
        $this->assertSame(['Müller-Einkauf'], $this->lists($phone));
    }

    /** Realtime announcements arrive for the household the phone looks at. */
    public function test_the_realtime_channel_follows_the_chosen_household(): void
    {
        $token = $this->phone();

        foreach ([$this->huber, $this->mueller] as $household) {
            Auth::forgetGuards();
            $this->withToken($token)->putJson('/api/auth/household', ['household_id' => $household->id])->assertOk();
            Auth::forgetGuards();

            $this->withToken($token)->getJson('/api/realtime/config')
                ->assertOk()
                ->assertJsonPath('channel', 'household.'.$household->id);
        }
    }

    public function test_a_foreign_household_cannot_be_chosen(): void
    {
        $foreign = Household::factory()->create();
        Checklist::factory()->create(['household_id' => $foreign->id, 'name' => 'Fremd']);
        $token = $this->phone();

        $this->withToken($token)->putJson('/api/auth/household', ['household_id' => $foreign->id])->assertNotFound();

        // Even written into the token by hand, it falls back to an own one.
        $this->grandma->tokens()->update(['household_id' => $foreign->id]);
        $this->assertNotContains('Fremd', $this->lists($token));
    }

    public function test_the_rights_follow_the_household(): void
    {
        $token = $this->phone();

        $this->withToken($token)->putJson('/api/auth/household', ['household_id' => $this->mueller->id])
            ->assertJsonFragment(['permissions' => array_map(fn (Permission $p): string => $p->value, Permission::cases())]);

        Auth::forgetGuards();

        $huber = $this->withToken($token)->putJson('/api/auth/household', ['household_id' => $this->huber->id])->json('permissions');
        $this->assertNotContains(Permission::HouseholdManage->value, $huber);
        $this->assertContains(Permission::PointsAward->value, $huber);
    }

    public function test_one_pin_unlocks_only_where_the_account_manages(): void
    {
        app(ParentPin::class)->set($this->grandma, '4711');

        $this->assertTrue($this->wallUnlocks($this->mueller, '4711'));
        $this->assertFalse($this->wallUnlocks($this->huber, '4711'));
    }

    private function wallUnlocks(Household $household, string $pin): bool
    {
        Auth::forgetGuards();
        app(HouseholdContext::class)->setId((int) $household->id);
        $pairing = app(DevicePairingService::class);
        $token = $pairing->redeem($pairing->createCode($household, 'Flur')->pairing_code)['token'];

        return $this->withToken($token)->postJson('/api/wall/pin', ['pin' => $pin])->status() === 200;
    }

    public function test_the_operator_joins_no_household(): void
    {
        $operator = User::factory()->create(['household_id' => null]);
        $operator->forceFill(['is_operator' => true])->save();

        $this->expectException(LogicException::class);

        $operator->memberships()->create(['household_id' => $this->huber->id, 'accepted_at' => now()]);
    }

    public function test_inviting_the_operator_looks_the_same_and_does_nothing(): void
    {
        Notification::fake();
        $operator = User::factory()->create(['household_id' => null, 'email' => 'betrieb@example.test']);
        $operator->forceFill(['is_operator' => true])->save();

        $result = app(Memberships::class)->invite($this->huber, Person::factory()->create(['household_id' => $this->huber->id]), 'Betrieb@example.test');

        $this->assertNull($result);
        $this->assertSame(0, $operator->memberships()->count());
        Notification::assertNothingSent();
    }

    public function test_a_known_address_is_asked_and_joins_only_on_yes(): void
    {
        Notification::fake();
        $third = Household::factory()->create(['name' => 'Schmid']);
        $person = Person::factory()->create(['household_id' => $third->id]);

        app(Memberships::class)->invite($third, $person, $this->grandma->email);

        $this->assertSame(1, User::query()->where('email', $this->grandma->email)->count());
        Notification::assertSentTo($this->grandma, HouseholdInvitation::class);
        Notification::assertNotSentTo($this->grandma, Invitation::class);

        $token = $this->phone();
        $this->withToken($token)->getJson('/api/auth/me')
            ->assertJsonPath('invitations.0.name', 'Schmid')
            ->assertJsonCount(2, 'households');

        // Not yet a member: switching there is refused.
        $this->withToken($token)->putJson('/api/auth/household', ['household_id' => $third->id])->assertNotFound();

        $this->withToken($token)->postJson("/api/auth/invitations/{$third->id}")
            ->assertOk()
            ->assertJsonCount(3, 'households')
            ->assertJsonCount(0, 'invitations');
    }

    public function test_an_invitation_can_be_declined(): void
    {
        $third = Household::factory()->create();
        app(Memberships::class)->invite($third, Person::factory()->create(['household_id' => $third->id]), $this->grandma->email);

        $this->withToken($this->phone())->deleteJson("/api/auth/invitations/{$third->id}")
            ->assertOk()
            ->assertJsonCount(0, 'invitations');

        $this->assertNull($this->grandma->memberships()->where('household_id', $third->id)->first());
    }

    public function test_leaving_takes_the_household_s_push_along(): void
    {
        $token = $this->phone();
        $this->withToken($token)->putJson('/api/auth/household', ['household_id' => $this->huber->id])->assertOk();
        $this->withToken($token)->postJson('/api/push/subscribe', [
            'endpoint' => 'https://push.example/oma',
            'keys' => ['p256dh' => 'p', 'auth' => 'a'],
        ])->assertCreated();

        $this->assertSame(2, Device::withoutHouseholdScope()->where('push_endpoint', 'https://push.example/oma')->count());

        Auth::forgetGuards();
        $this->withToken($token)->deleteJson('/api/auth/household')->assertNoContent();

        $this->assertSame(
            [$this->mueller->id],
            Device::withoutHouseholdScope()->where('push_endpoint', 'https://push.example/oma')->pluck('household_id')->all(),
        );
        $this->assertSame(['Müller-Einkauf'], $this->lists($token));
    }

    public function test_the_last_manager_cannot_leave(): void
    {
        $token = $this->phone();
        $this->withToken($token)->putJson('/api/auth/household', ['household_id' => $this->mueller->id])->assertOk();

        Auth::forgetGuards();
        $this->withToken($token)->deleteJson('/api/auth/household')->assertUnprocessable();
    }

    public function test_a_phone_in_two_households_reads_whose_message_it_is(): void
    {
        $device = Device::factory()->create([
            'household_id' => $this->mueller->id,
            'kind' => DeviceKind::Mobile,
            'user_id' => $this->grandma->id,
        ]);

        $payload = app(WebPushService::class)->payloadFor($device, ['de' => ['title' => 'Belohnung angefragt', 'url' => '/']]);

        $this->assertSame('Müller · Belohnung angefragt', $payload['title']);
        $this->assertSame('/?household='.$this->mueller->id, $payload['url']);

        $this->grandma->memberships()->where('household_id', $this->huber->id)->delete();

        $this->assertSame('Belohnung angefragt', app(WebPushService::class)->payloadFor($device, ['de' => ['title' => 'Belohnung angefragt']])['title']);
    }

    public function test_menu_and_filters_are_kept_per_household(): void
    {
        $token = $this->phone();

        $this->withToken($token)->putJson('/api/auth/household', ['household_id' => $this->huber->id])->assertOk();
        $this->withToken($token)->putJson('/api/preferences', ['hidden_areas' => ['meals'], 'look' => 'glass'])->assertOk();

        Auth::forgetGuards();
        $this->withToken($token)->putJson('/api/auth/household', ['household_id' => $this->mueller->id])->assertOk();

        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/preferences')
            ->assertJsonPath('preferences.hidden_areas', [])
            // The look belongs to the account.
            ->assertJsonPath('preferences.look', 'glass');
    }

    public function test_a_suspended_household_is_left_for_another(): void
    {
        $token = $this->phone();
        $this->withToken($token)->putJson('/api/auth/household', ['household_id' => $this->huber->id])->assertOk();

        $this->huber->forceFill(['suspended_at' => now()])->save();

        $this->assertSame(['Müller-Einkauf'], $this->lists($token));
    }

    public function test_removing_the_last_membership_deletes_the_account(): void
    {
        $memberships = app(Memberships::class);

        foreach (Membership::query()->where('user_id', $this->grandma->id)->get() as $membership) {
            $memberships->remove($membership);
        }

        $this->assertNull(User::query()->find($this->grandma->id));
    }

    /** A guest at the Hubers is no administrator there, even signed in to the admin. */
    public function test_the_admin_opens_only_households_the_account_manages(): void
    {
        Checklist::query()->delete();
        $this->actingAs($this->grandma, 'web');

        $this->post('/household', ['household_id' => $this->huber->id])->assertNotFound();

        $this->get('/admin')->assertSuccessful()->assertDontSee('id="fahoba-household"', false);
        $this->assertSame($this->mueller->id, session(ActiveHousehold::SESSION_KEY));

        // A session pointing at the Hubers by hand falls back to the Müllers.
        $this->withSession([ActiveHousehold::SESSION_KEY => $this->huber->id])->get('/admin')->assertSuccessful();
        $this->assertSame($this->mueller->id, session(ActiveHousehold::SESSION_KEY));
    }

    public function test_someone_managing_two_households_switches_in_the_admin(): void
    {
        $this->grandma->memberships()->where('household_id', $this->huber->id)->firstOrFail()->person?->update(['role' => PersonRole::Adult]);
        $this->actingAs($this->grandma, 'web');

        $this->get('/admin')->assertSuccessful()->assertSee('id="fahoba-household"', false);

        $this->post('/household', ['household_id' => $this->huber->id])->assertRedirect();
        $this->assertSame($this->huber->id, session(ActiveHousehold::SESSION_KEY));
    }
}
