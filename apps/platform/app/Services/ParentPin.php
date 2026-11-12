<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Permission;
use App\Models\Household;
use App\Models\User;
use App\Support\Abilities;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * The parent PIN and its short unlock.
 *
 * The PIN is not asked for again on every protected action: whoever approves
 * three rewards would otherwise type it three times. Instead one entry
 * unlocks the device for a few minutes.
 *
 * The unlock is tied to the device, not to a session: there is no login at
 * the wall, and the unlock should lapse when you walk away.
 */
final class ParentPin
{
    private const UNLOCK_MINUTES = 5;

    /** Each parent keeps their own; null removes it. */
    public function set(User $user, ?string $pin): void
    {
        $user->forceFill(['pin_hash' => $pin === null ? null : Hash::make($pin)])->save();
    }

    /**
     * Whether any parent of the household knows this PIN.
     *
     * Only accounts that may manage the household count: a parent whose
     * right was taken away does not unlock the wall with an old PIN.
     */
    public function verify(Household $household, string $pin): bool
    {
        return $this->parents($household)
            ->contains(fn (User $user): bool => Hash::check($pin, (string) $user->pin_hash));
    }

    /**
     * The accounts whose PIN unlocks this household's wall.
     *
     * @return Collection<int, User>
     */
    public function parents(Household $household): Collection
    {
        // One PIN per account, valid wherever that account manages: a parent
        // in two households remembers one, not two.
        return User::query()
            ->whereHas('memberships', fn (Builder $membership) => $membership->accepted()->where('household_id', $household->id))
            ->whereNotNull('pin_hash')
            ->get()
            ->filter(fn (User $user): bool => in_array(
                Permission::HouseholdManage,
                $this->abilities()->ofIn($user, (int) $household->id),
                true,
            ))
            ->values();
    }

    /*
     * Resolved late: Abilities itself depends on this service, a constructor
     * dependency would be a circle.
     */
    private function abilities(): Abilities
    {
        return app(Abilities::class);
    }

    /** @return bool whether the PIN was correct */
    public function unlock(Household $household, int|string $device, string $pin): bool
    {
        if (! $this->verify($household, $pin)) {
            return false;
        }

        Cache::put($this->cacheKey($device), true, now()->addMinutes(self::UNLOCK_MINUTES));

        return true;
    }

    public function isUnlocked(Household $household, int|string $device): bool
    {
        // Without a PIN nothing is protected — so there is nothing to unlock
        // either. An invented default PIN would be printed in every manual.
        if (! $this->required($household)) {
            return true;
        }

        return Cache::get($this->cacheKey($device)) === true;
    }

    /** Without any parent's PIN the wall asks for nothing. */
    public function required(Household $household): bool
    {
        return $this->parents($household)->isNotEmpty();
    }

    public function lock(int|string $device): void
    {
        Cache::forget($this->cacheKey($device));
    }

    public function unlockedUntil(): int
    {
        return self::UNLOCK_MINUTES * 60;
    }

    private function cacheKey(int|string $device): string
    {
        return "fahoba:pin-unlocked:{$device}";
    }
}
