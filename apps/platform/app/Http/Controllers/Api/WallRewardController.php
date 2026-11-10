<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\Permission;
use App\Enums\PersonRole;
use App\Exceptions\PermissionDenied;
use App\Http\Controllers\Controller;
use App\Models\Person;
use App\Models\Reward;
use App\Models\RewardRedemption;
use App\Models\User;
use App\Notifications\RewardRequested;
use App\Services\PointLedger;
use App\Support\Abilities;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Rewards — in two steps.
 *
 * The child asks (open), a parent approves (behind the PIN). Points are only
 * booked on approval: a request nobody approves must not consume credit.
 * A parent's account skips the asking and redeems at once.
 */
final class WallRewardController extends Controller
{
    public function __construct(
        private readonly PointLedger $ledger,
        private readonly Abilities $abilities,
    ) {}

    public function index(): JsonResponse
    {
        $rewards = Reward::query()
            ->where('active', true)
            ->orderBy('position')
            ->orderBy('cost')
            ->get()
            ->map(fn (Reward $reward): array => [
                'id' => $reward->id,
                'name' => $reward->name,
                'icon' => $reward->icon,
                'description' => $reward->description,
                'cost' => $reward->cost,
            ])
            ->all();

        $pending = RewardRedemption::query()
            ->whereNull('approved_at')
            ->whereNull('rejected_at')
            ->with(['reward', 'person'])
            ->orderBy('id')
            ->get()
            ->map(fn (RewardRedemption $redemption): array => [
                'id' => $redemption->id,
                'reward_id' => $redemption->reward_id,
                'reward' => $redemption->reward?->name,
                'person_id' => $redemption->person_id,
                'person' => $redemption->person?->name,
                'avatar' => $redemption->person?->avatarUrl(),
                'cost' => $redemption->cost,
            ])
            ->all();

        return response()->json(['rewards' => $rewards, 'pending' => $pending]);
    }

    public function request(Request $request, Reward $reward): JsonResponse
    {
        $data = $request->validate(['person_id' => ['required', 'integer']]);

        $person = Person::query()->find($data['person_id']);

        if ($person === null) {
            throw ValidationException::withMessages(['person_id' => __('Unknown person.')]);
        }

        /*
         * On the phone someone is signed in: a child wishes for itself only.
         * Whoever may award stars may also wish on a child's behalf. The wall
         * has nobody signed in and keeps offering every child.
         */
        $actor = $request->user();

        if ($actor instanceof User
            && $this->abilities->personOf($actor) !== $person->id
            && ! $this->abilities->allows($actor, Permission::PointsAward)) {
            throw new PermissionDenied(pinHelps: false);
        }

        /*
         * Children tap twice — three requests within a second have happened —
         * and every approval debits. While a request for the same reward is
         * open, tapping again answers with it. Behind a lock on the person,
         * because those taps arrive at the same time.
         */
        [$redemption, $created] = DB::transaction(function () use ($person, $reward): array {
            Person::query()->whereKey($person->id)->lockForUpdate()->first();

            $open = RewardRedemption::query()
                ->where('reward_id', $reward->id)
                ->where('person_id', $person->id)
                ->whereNull('approved_at')
                ->whereNull('rejected_at')
                ->first();

            if ($open !== null) {
                return [$open, false];
            }

            /*
             * Only a friendly hint here, the binding check follows on approval:
             * the balance can change between request and approval.
             */
            $balance = $this->ledger->balances()[$person->id] ?? 0;

            if ($balance < $reward->cost) {
                throw ValidationException::withMessages([
                    'person_id' => trans_choice('One more star is needed for that.|:count more stars are needed for that.', $reward->cost - $balance),
                ]);
            }

            return [RewardRedemption::create([
                'reward_id' => $reward->id,
                'person_id' => $person->id,
                'cost' => $reward->cost,
            ]), true];
        });

        /*
         * A parent on the phone would only ask themselves: whoever may award
         * stars redeems at once — an open request of the child included. The
         * wall keeps the two steps; nobody is signed in there.
         */
        if ($actor instanceof User && $this->abilities->allows($actor, Permission::PointsAward)) {
            return match ($this->ledger->approveRedemption($redemption, (int) $actor->getKey())) {
                'approved' => response()->json(['id' => $redemption->id, 'pending' => false], $created ? 201 : 200),
                'decided' => throw ValidationException::withMessages(['person_id' => __('This request has already been decided.')]),
                'insufficient' => throw ValidationException::withMessages(['person_id' => __('The balance is no longer enough.')]),
            };
        }

        // Already asked: the parents know, a second push would only nag.
        if (! $created) {
            return response()->json(['id' => $redemption->id, 'pending' => true]);
        }

        // The parents' accounts: their bell, their phones.
        $householdId = (int) $redemption->household_id;
        Notification::send(
            User::query()->whereHas('memberships', fn (Builder $membership) => $membership
                ->accepted()
                ->where('household_id', $householdId)
                ->whereHas('person', fn (Builder $person) => $person->where('role', PersonRole::Adult->value)))
                ->get(),
            new RewardRequested($householdId, $person->name, $reward->name, (int) $reward->cost),
        );

        return response()->json(['id' => $redemption->id, 'pending' => true], 201);
    }

    public function approve(Request $request, RewardRedemption $redemption): JsonResponse
    {
        $outcome = $this->ledger->approveRedemption(
            $redemption,
            $request->user() instanceof User ? (int) $request->user()->getKey() : null,
        );

        return match ($outcome) {
            'approved' => response()->json(['approved' => true]),
            'decided' => throw ValidationException::withMessages(['redemption' => __('This request has already been decided.')]),
            'insufficient' => throw ValidationException::withMessages(['redemption' => __('The balance is no longer enough.')]),
        };
    }

    public function reject(RewardRedemption $redemption): JsonResponse
    {
        if (! $redemption->isPending()) {
            throw ValidationException::withMessages(['redemption' => __('This request has already been decided.')]);
        }

        // No booking: rejected means nothing happened.
        $redemption->forceFill(['rejected_at' => Carbon::now()])->save();

        return response()->json(['rejected' => true]);
    }
}
