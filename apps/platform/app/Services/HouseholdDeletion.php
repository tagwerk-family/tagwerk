<?php

declare(strict_types=1);

namespace App\Services;

use App\Integrations\Photos\Folder\FolderPhotoSource;
use App\Models\Household;
use App\Models\Membership;
use App\Models\Person;
use App\Models\Photo;
use App\Models\Recipe;
use App\Models\User;
use Fahoba\PluginApi\Households\HouseholdDeleting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes a household with everything it owns: its rows through the foreign
 * keys, its files by hand (uploads and its server photo folder), and every account it leaves without a household.
 *
 * `HouseholdDeleting` goes out first; a listener that throws stops it — a
 * subscription that could not be cancelled must not outlive its household.
 * Files go after the rows are gone: a failed delete keeps the photos of a
 * family that still exists.
 */
final class HouseholdDeletion
{
    public function delete(Household $household): void
    {
        $id = (int) $household->id;

        event(new HouseholdDeleting($id));

        $local = [];
        $public = [];

        foreach (Photo::withoutHouseholdScope()->withTrashed()->where('household_id', $id)->pluck('path') as $path) {
            $local[] = (string) $path;
            $local[] = PhotoImage::thumbnailPath((string) $path);
        }

        foreach (Recipe::withoutHouseholdScope()->where('household_id', $id)->get() as $recipe) {
            if (is_string($recipe->photo_path) && $recipe->photo_path !== '') {
                $local[] = $recipe->photo_path;
            }

            array_push($local, ...$recipe->pagePaths());
        }

        foreach (Person::withoutHouseholdScope()->where('household_id', $id)->whereNotNull('avatar_path')->pluck('avatar_path') as $path) {
            $public[] = (string) $path;
        }

        $accounts = Membership::query()->where('household_id', $id)->pluck('user_id')->all();

        DB::transaction(function () use ($household, $accounts): void {
            // One statement: the foreign keys cascade, task completions included.
            Household::query()->whereKey($household->id)->delete();

            User::query()
                ->whereKey($accounts)
                ->where('is_operator', false)
                ->whereDoesntHave('memberships')
                ->get()
                ->each(fn (User $user): ?bool => $user->delete());
        });

        Storage::disk('local')->delete($local);
        Storage::disk('public')->delete($public);
        FolderPhotoSource::forget($id);
        HouseholdNotices::forget($id);
    }
}
