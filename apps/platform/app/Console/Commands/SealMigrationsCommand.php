<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\MigrationSeal;
use Illuminate\Console\Command;

/**
 * Part of a release: records the hash of every migration not sealed yet,
 * so the test refuses a later change to it (MigrationsAppendOnlyTest).
 */
final class SealMigrationsCommand extends Command
{
    protected $signature = 'fahoba:seal-migrations';

    protected $description = 'Records the released migrations so they cannot change afterwards';

    public function handle(MigrationSeal $seal): int
    {
        $this->info("Sealed {$seal->sealNew()} migrations.");

        return self::SUCCESS;
    }
}
