<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ListItem;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Takes ticked items off every list once they have been done for a while.
 *
 * Twelve hours, not "at night": households live in different time zones,
 * and one night would be another's afternoon. What was bought in the
 * evening can still be checked, and by the next morning the list is tidy.
 * A provider keeps its own history; the next pull only brings open items.
 */
final class ClearDoneItemsCommand extends Command
{
    public const HOURS = 12;

    protected $signature = 'fahoba:clear-done-items';

    protected $description = 'Removes list items that have been ticked off for twelve hours';

    public function handle(): int
    {
        $removed = ListItem::withoutHouseholdScope()
            ->where('done_at', '<', Carbon::now()->subHours(self::HOURS))
            ->delete();

        $this->info("Removed {$removed} items.");

        return self::SUCCESS;
    }
}
