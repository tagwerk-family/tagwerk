<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\BuildHouseholdArchiveJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/** Exports and unfinished imports are copies of a family's data; they do not linger. */
final class PurgeHouseholdArchivesCommand extends Command
{
    protected $signature = 'fahoba:purge-household-archives';

    protected $description = 'Deletes household exports and uploads older than a week';

    public function handle(): int
    {
        $disk = Storage::disk('local');
        $before = now()->subDays(BuildHouseholdArchiveJob::DAYS)->getTimestamp();

        foreach ([...$disk->allFiles('exports'), ...$disk->allFiles('imports')] as $file) {
            if ($disk->lastModified($file) < $before) {
                $disk->delete($file);
            }
        }

        return self::SUCCESS;
    }
}
