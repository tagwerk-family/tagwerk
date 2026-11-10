<?php

declare(strict_types=1);

namespace Tests\Feature\Wall;

use App\Jobs\AnnounceHouseholdUpdateJob;
use App\Models\Household;
use App\Models\Person;
use App\Models\PointEntry;
use App\Models\Reward;
use App\Models\RewardRedemption;
use App\Models\User;
use App\Notifications\RewardRequested;
use App\Services\DevicePairingService;
use App\Services\PointLedger;
use App\Support\HouseholdContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class WallRewardTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private string $token;

    private Person $child;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create(['timezone' => 'Europe/Vienna']);
        app(HouseholdContext::class)->setId((int) $this->household->id);

        $device = app(DevicePairingService::class)->createCode($this->household, 'Küche');
        $this->token = app(DevicePairingService::class)->redeem($device->pairing_code)['token'];

        $this->child = Person::factory()->child()->create([
            'household_id' => $this->household->id,
            'name' => 'Max',
        ]);
    }

    private function balance(int $points): void
    {
        PointEntry::factory()->create([
            'household_id' => $this->household->id,
            'person_id' => $this->child->id,
            'points' => $points,
        ]);
    }

    private function reward(int $cost = 20): Reward
    {
        return Reward::factory()->create([
            'household_id' => $this->household->id,
            'name' => 'Kinoabend',
            'icon' => '🎬',
            'cost' => $cost,
        ]);
    }

    public function test_rewards_are_visible_without_pin(): void
    {
        $this->parentWithPin($this->household);
        $this->reward();

        $this->withToken($this->token)
            ->getJson('/api/wall/rewards')
            ->assertOk()
            ->assertJsonPath('rewards.0.name', 'Kinoabend')
            // The card carries the symbol, not the text — whoever cannot
            // read yet looks for it.
            ->assertJsonPath('rewards.0.icon', '🎬')
            ->assertJsonPath('rewards.0.cost', 20);
    }

    public function test_inactive_reward_does_not_appear(): void
    {
        Reward::factory()->create([
            'household_id' => $this->household->id,
            'active' => false,
        ]);

        $this->withToken($this->token)
            ->getJson('/api/wall/rewards')
            ->assertOk()
            ->assertJsonCount(0, 'rewards');
    }

    /** On the phone a child wishes for itself — not for a sibling. */
    public function test_a_child_account_requests_only_for_itself(): void
    {
        $this->balance(50);
        $reward = $this->reward();
        $sister = Person::factory()->child()->create(['household_id' => $this->household->id, 'name' => 'Lena']);
        PointEntry::factory()->create(['household_id' => $this->household->id, 'person_id' => $sister->id, 'points' => 50]);

        $phone = User::factory()->create(['household_id' => $this->household->id, 'person_id' => $this->child->id])
            ->createToken('phone')->plainTextToken;

        $this->withToken($phone)
            ->postJson("/api/wall/rewards/{$reward->id}/request", ['person_id' => $sister->id])
            ->assertForbidden();

        $this->withToken($phone)
            ->postJson("/api/wall/rewards/{$reward->id}/request", ['person_id' => $this->child->id])
            ->assertCreated();
    }

    /** Whoever may award stars redeems for a child at once — no request to approve, no push. */
    public function test_a_parent_account_redeems_for_a_child_at_once(): void
    {
        Notification::fake();

        $this->balance(50);
        $reward = $this->reward();
        $parent = Person::factory()->create(['household_id' => $this->household->id, 'name' => 'Mama']);

        $phone = User::factory()->create(['household_id' => $this->household->id, 'person_id' => $parent->id])
            ->createToken('phone')->plainTextToken;

        $this->withToken($phone)
            ->postJson("/api/wall/rewards/{$reward->id}/request", ['person_id' => $this->child->id])
            ->assertCreated()
            ->assertJsonPath('pending', false);

        $this->assertSame(30, (int) PointEntry::query()->sum('points'));
        $this->assertNotNull(RewardRedemption::query()->sole()->approved_at);
        Notification::assertNothingSent();
    }

    /** The child asked on the wall; the parent tapping the same reward settles that request. */
    public function test_a_parent_account_redeeming_settles_the_open_request(): void
    {
        $this->balance(50);
        $reward = $this->reward();

        $this->withToken($this->token)
            ->postJson("/api/wall/rewards/{$reward->id}/request", ['person_id' => $this->child->id])
            ->assertCreated();

        Auth::forgetGuards();

        $parent = Person::factory()->create(['household_id' => $this->household->id, 'name' => 'Mama']);
        $phone = User::factory()->create(['household_id' => $this->household->id, 'person_id' => $parent->id])
            ->createToken('phone')->plainTextToken;

        $this->withToken($phone)
            ->postJson("/api/wall/rewards/{$reward->id}/request", ['person_id' => $this->child->id])
            ->assertOk()
            ->assertJsonPath('pending', false);

        $this->assertSame(1, RewardRedemption::query()->count());
        $this->assertSame(30, (int) PointEntry::query()->sum('points'));
    }

    public function test_request_does_not_debit_yet(): void
    {
        $this->balance(50);
        $reward = $this->reward();

        $this->withToken($this->token)
            ->postJson("/api/wall/rewards/{$reward->id}/request", ['person_id' => $this->child->id])
            ->assertCreated()
            ->assertJsonPath('pending', true);

        $this->assertSame(50, (int) PointEntry::query()->sum('points'));
        $this->assertSame(1, RewardRedemption::query()->count());

        // The card that was tapped finds its request by the reward's id.
        $this->withToken($this->token)
            ->getJson('/api/wall/rewards')
            ->assertJsonPath('pending.0.reward_id', $reward->id)
            ->assertJsonPath('pending.0.person_id', $this->child->id);
    }

    /**
     * Children tap twice, and each tap used to be a request of its own: a
     * parent approving all of them debited the reward several times. While
     * one is open, tapping again answers with it; after a decision a new
     * request is possible.
     */
    public function test_tapping_again_keeps_one_open_request(): void
    {
        Notification::fake();
        $parent = $this->parentAccount();
        $this->balance(50);
        $reward = $this->reward(10);

        $first = $this->withToken($this->token)
            ->postJson("/api/wall/rewards/{$reward->id}/request", ['person_id' => $this->child->id])
            ->assertCreated()
            ->json('id');

        $this->withToken($this->token)
            ->postJson("/api/wall/rewards/{$reward->id}/request", ['person_id' => $this->child->id])
            ->assertOk()
            ->assertJsonPath('id', $first)
            ->assertJsonPath('pending', true);

        $this->assertSame(1, RewardRedemption::query()->count());
        Notification::assertSentToTimes($parent, RewardRequested::class, 1);

        RewardRedemption::query()->findOrFail($first)->forceFill(['approved_at' => now()])->save();

        $this->withToken($this->token)
            ->postJson("/api/wall/rewards/{$reward->id}/request", ['person_id' => $this->child->id])
            ->assertCreated();

        $this->assertSame(2, RewardRedemption::query()->count());
    }

    /**
     * The request has to reach someone on its own — otherwise it sits on the
     * tablet until a parent happens to walk by.
     */
    public function test_request_notifies_the_parents(): void
    {
        Notification::fake();
        $parent = $this->parentAccount();
        $childAccount = User::factory()->create(['household_id' => $this->household->id, 'person_id' => $this->child->id]);

        $this->balance(50);
        $reward = $this->reward();

        $this->withToken($this->token)
            ->postJson("/api/wall/rewards/{$reward->id}/request", ['person_id' => $this->child->id])
            ->assertCreated();

        Notification::assertSentTo($parent, RewardRequested::class, function (RewardRequested $message, array $channels) use ($parent): bool {
            $this->assertSame('Max möchte „Kinoabend“ einlösen (20 Sterne).', $message->body());
            $this->assertSame('reward', $message->toPush($parent)['tag'] ?? null);

            return $message->householdId === (int) $this->household->id;
        });
        Notification::assertNotSentTo($childAccount, RewardRequested::class);
    }

    private function parentAccount(): User
    {
        $person = Person::factory()->create(['household_id' => $this->household->id, 'name' => 'Mama']);

        return User::factory()->create(['household_id' => $this->household->id, 'person_id' => $person->id]);
    }

    public function test_rejected_request_notifies_nobody(): void
    {
        Notification::fake();

        $this->balance(5);
        $reward = $this->reward(20);

        $this->withToken($this->token)
            ->postJson("/api/wall/rewards/{$reward->id}/request", ['person_id' => $this->child->id])
            ->assertStatus(422);

        Notification::assertNothingSent();
    }

    public function test_request_without_enough_balance_is_rejected(): void
    {
        $this->balance(5);
        $reward = $this->reward(20);

        $this->withToken($this->token)
            ->postJson("/api/wall/rewards/{$reward->id}/request", ['person_id' => $this->child->id])
            ->assertStatus(422);

        $this->assertSame(0, RewardRedemption::query()->count());
    }

    public function test_approval_requires_the_pin(): void
    {
        $this->parentWithPin($this->household);
        $this->balance(50);

        $redemption = RewardRedemption::factory()->create([
            'household_id' => $this->household->id,
            'reward_id' => $this->reward()->id,
            'person_id' => $this->child->id,
            'cost' => 20,
        ]);

        $this->withToken($this->token)
            ->postJson("/api/wall/redemptions/{$redemption->id}/approve")
            ->assertStatus(423);

        $this->assertSame(50, (int) PointEntry::query()->sum('points'));
    }

    public function test_approval_debits_and_records(): void
    {
        $this->parentWithPin($this->household);
        $this->balance(50);

        $redemption = RewardRedemption::factory()->create([
            'household_id' => $this->household->id,
            'reward_id' => $this->reward()->id,
            'person_id' => $this->child->id,
            'cost' => 20,
        ]);

        $this->withToken($this->token)->postJson('/api/wall/pin', ['pin' => '1234'])->assertOk();

        $this->withToken($this->token)
            ->postJson("/api/wall/redemptions/{$redemption->id}/approve")
            ->assertOk();

        $this->assertSame(30, (int) PointEntry::query()->sum('points'));
        $this->assertNotNull($redemption->refresh()->approved_at);
    }

    private function redemption(int $cost = 20): RewardRedemption
    {
        return RewardRedemption::factory()->create([
            'household_id' => $this->household->id,
            'reward_id' => $this->reward($cost)->id,
            'person_id' => $this->child->id,
            'cost' => $cost,
        ]);
    }

    /**
     * A parent's account on the phone: an adult person with the default
     * permissions. The household has a PIN, which plays no part there.
     */
    private function parentPhone(): string
    {
        $this->parentWithPin($this->household);
        $parent = Person::factory()->create(['household_id' => $this->household->id, 'name' => 'Mama']);

        return User::factory()->create(['household_id' => $this->household->id, 'person_id' => $parent->id])
            ->createToken('phone')->plainTextToken;
    }

    /** On the phone the account's permissions decide, never the PIN. */
    public function test_a_parent_account_approves_without_the_pin(): void
    {
        $this->balance(50);
        $redemption = $this->redemption();
        $phone = $this->parentPhone();

        $this->withToken($phone)
            ->postJson("/api/wall/redemptions/{$redemption->id}/approve")
            ->assertOk()
            ->assertJson(['approved' => true]);

        $this->assertSame(30, (int) PointEntry::query()->sum('points'));
        $this->assertNotNull($redemption->refresh()->approved_at);
        $this->assertNotNull($redemption->approved_by_user_id);
    }

    public function test_a_parent_account_rejects_without_the_pin(): void
    {
        $this->balance(50);
        $redemption = $this->redemption();

        $this->withToken($this->parentPhone())
            ->postJson("/api/wall/redemptions/{$redemption->id}/reject")
            ->assertOk();

        $this->assertNotNull($redemption->refresh()->rejected_at);
        $this->assertSame(50, (int) PointEntry::query()->sum('points'));
    }

    /**
     * The server keeps its cache in the database. Approving saves the
     * redemption and the debit in one transaction, and both announce the
     * same area: the second unique lock collided with the first, and
     * PostgreSQL aborted the whole transaction — a 500 on every approval.
     */
    public function test_approving_with_the_database_cache_of_the_server(): void
    {
        config(['cache.default' => 'database']);
        Queue::fake();
        $this->balance(50);
        $redemption = $this->redemption();
        $phone = $this->parentPhone();

        // A worker would have taken the setup's announcements by now. The
        // locks live outside the test's transaction and must go by hand.
        DB::connection('locks')->table('cache_locks')->delete();
        $this->beforeApplicationDestroyed(fn () => DB::connection('locks')->table('cache_locks')->delete());
        Queue::fake();

        $this->withToken($phone)
            ->postJson("/api/wall/redemptions/{$redemption->id}/approve")
            ->assertOk();

        $this->assertSame(30, (int) PointEntry::query()->sum('points'));
        Queue::assertPushedTimes(AnnounceHouseholdUpdateJob::class, 1);
    }

    /**
     * A wish is saved in a transaction too. The second one within a minute
     * met the first one's lock and failed the same way.
     */
    public function test_two_wishes_in_a_row_with_the_database_cache_of_the_server(): void
    {
        config(['cache.default' => 'database']);
        Queue::fake();
        $this->balance(50);
        $first = $this->reward(10);
        $second = $this->reward(20);
        $this->beforeApplicationDestroyed(fn () => DB::connection('locks')->table('cache_locks')->delete());

        foreach ([$first, $second] as $reward) {
            $this->withToken($this->token)
                ->postJson("/api/wall/rewards/{$reward->id}/request", ['person_id' => $this->child->id])
                ->assertCreated();
        }

        $this->assertSame(2, RewardRedemption::query()->count());
    }

    /** A child's account may wish, never approve: 403, because no PIN helps there. */
    public function test_a_child_account_cannot_approve(): void
    {
        $this->balance(50);
        $redemption = $this->redemption();
        $phone = User::factory()->create(['household_id' => $this->household->id, 'person_id' => $this->child->id])
            ->createToken('phone')->plainTextToken;

        $this->withToken($phone)
            ->postJson("/api/wall/redemptions/{$redemption->id}/approve")
            ->assertForbidden();

        $this->assertTrue($redemption->refresh()->isPending());
    }

    /**
     * Approved on the wall while the phone still lists it: the phone gets a
     * reason it can show, not a bare failure.
     */
    public function test_approving_a_request_decided_elsewhere_explains_why(): void
    {
        $this->balance(50);
        $redemption = $this->redemption();
        $redemption->forceFill(['approved_at' => now()])->save();

        $this->withToken($this->parentPhone())
            ->postJson("/api/wall/redemptions/{$redemption->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('errors.redemption.0', 'Diese Anfrage ist bereits entschieden.');
    }

    /** Two wishes that each fit, together not: the second approval says so. */
    public function test_approving_beyond_the_balance_explains_why(): void
    {
        $this->balance(30);
        $first = $this->redemption();
        $second = $this->redemption();
        $phone = $this->parentPhone();

        $this->withToken($phone)->postJson("/api/wall/redemptions/{$first->id}/approve")->assertOk();

        $this->withToken($phone)
            ->postJson("/api/wall/redemptions/{$second->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('errors.redemption.0', 'Das Guthaben reicht nicht mehr.');

        $this->assertSame(10, (int) PointEntry::query()->sum('points'));
    }

    public function test_rejection_debits_nothing(): void
    {
        $this->balance(50);

        $redemption = RewardRedemption::factory()->create([
            'household_id' => $this->household->id,
            'reward_id' => $this->reward()->id,
            'person_id' => $this->child->id,
            'cost' => 20,
        ]);

        $this->withToken($this->token)
            ->postJson("/api/wall/redemptions/{$redemption->id}/reject")
            ->assertOk();

        $this->assertSame(50, (int) PointEntry::query()->sum('points'));
        $this->assertNotNull($redemption->refresh()->rejected_at);
    }

    public function test_second_approval_does_not_debit_again(): void
    {
        $this->balance(50);

        $redemption = RewardRedemption::factory()->create([
            'household_id' => $this->household->id,
            'reward_id' => $this->reward()->id,
            'person_id' => $this->child->id,
            'cost' => 20,
        ]);

        $this->withToken($this->token)
            ->postJson("/api/wall/redemptions/{$redemption->id}/approve")
            ->assertOk();

        $this->withToken($this->token)
            ->postJson("/api/wall/redemptions/{$redemption->id}/approve")
            ->assertStatus(422);

        $this->assertSame(30, (int) PointEntry::query()->sum('points'));
    }

    /**
     * The overlap, not the sequential case: two approvals read the same
     * pending redemption before either has booked. Reproduced with two
     * independently loaded instances — exactly what two concurrent requests
     * would hold in memory.
     */
    public function test_overlapping_approvals_debit_only_once(): void
    {
        $this->balance(50);

        $redemption = RewardRedemption::factory()->create([
            'household_id' => $this->household->id,
            'reward_id' => $this->reward()->id,
            'person_id' => $this->child->id,
            'cost' => 20,
        ]);

        $first = RewardRedemption::query()->findOrFail($redemption->id);
        $second = RewardRedemption::query()->findOrFail($redemption->id);

        $ledger = app(PointLedger::class);

        $this->assertSame('approved', $ledger->approveRedemption($first));
        $this->assertSame('decided', $ledger->approveRedemption($second));

        $this->assertSame(30, (int) PointEntry::query()->sum('points'));
    }

    /** Two different wishes of the same child must not cost more together than is available. */
    public function test_two_redemptions_do_not_overdraw_the_balance(): void
    {
        $this->balance(30);

        $wishes = RewardRedemption::factory()->count(2)->create([
            'household_id' => $this->household->id,
            'reward_id' => $this->reward()->id,
            'person_id' => $this->child->id,
            'cost' => 20,
        ]);

        $ledger = app(PointLedger::class);

        $this->assertSame('approved', $ledger->approveRedemption($wishes[0]));
        $this->assertSame('insufficient', $ledger->approveRedemption($wishes[1]));

        $this->assertSame(10, (int) PointEntry::query()->sum('points'));
        $this->assertNull($wishes[1]->refresh()->approved_at);
    }

    /** The last line of defence lives in the database, not in the code. */
    public function test_a_redemption_has_at_most_one_debit(): void
    {
        $redemption = RewardRedemption::factory()->create([
            'household_id' => $this->household->id,
            'reward_id' => $this->reward()->id,
            'person_id' => $this->child->id,
            'cost' => 20,
        ]);

        $debit = [
            'household_id' => $this->household->id,
            'person_id' => $this->child->id,
            'points' => -20,
            'reason' => 'Eingelöst',
            'reward_redemption_id' => $redemption->id,
        ];

        PointEntry::query()->create($debit);

        $this->expectException(UniqueConstraintViolationException::class);

        PointEntry::query()->create($debit);
    }

    /**
     * The balance may have dropped between request and approval — for
     * instance because another redemption was approved first.
     */
    public function test_approval_with_insufficient_balance_is_rejected(): void
    {
        $this->balance(10);

        $redemption = RewardRedemption::factory()->create([
            'household_id' => $this->household->id,
            'reward_id' => $this->reward()->id,
            'person_id' => $this->child->id,
            'cost' => 20,
        ]);

        $this->withToken($this->token)
            ->postJson("/api/wall/redemptions/{$redemption->id}/approve")
            ->assertStatus(422);

        $this->assertSame(10, (int) PointEntry::query()->sum('points'));
        $this->assertTrue($redemption->refresh()->isPending());
    }

    public function test_pending_requests_appear_in_the_overview(): void
    {
        RewardRedemption::factory()->create([
            'household_id' => $this->household->id,
            'reward_id' => $this->reward()->id,
            'person_id' => $this->child->id,
            'cost' => 20,
        ]);

        $this->withToken($this->token)
            ->getJson('/api/wall/rewards')
            ->assertOk()
            ->assertJsonCount(1, 'pending')
            ->assertJsonPath('pending.0.person', 'Max')
            ->assertJsonPath('pending.0.reward', 'Kinoabend');
    }
}
