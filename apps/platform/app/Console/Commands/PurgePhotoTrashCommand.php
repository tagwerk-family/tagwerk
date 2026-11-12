<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Photo;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Empties the photo trash of every household: what has been deleted for
 * Photo::TRASH_DAYS goes for good, file and row. One by one, so that the
 * model hook takes the files along.
 */
final class PurgePhotoTrashCommand extends Command
{
    protected $signature = 'fahoba:purge-photo-trash';

    protected $description = 'Removes photos that have been in the trash for thirty days';

    public function handle(): int
    {
        $purged = 0;

        Photo::withoutHouseholdScope()
            ->onlyTrashed()
            ->where('deleted_at', '<', Carbon::now()->subDays(Photo::TRASH_DAYS))
            ->each(function (Photo $photo) use (&$purged): void {
                $photo->forceDelete();
                $purged++;
            });

        $this->info("Removed {$purged} photos.");

        return self::SUCCESS;
    }
}
