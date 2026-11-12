<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\FrontendPackage;
use Illuminate\Console\Command;

/**
 * Prints the directory nginx serves for `FAHOBA_FRONTEND`, or fails with
 * the reason — the entrypoint stops the start then, rather than serve a
 * blank page.
 */
final class FrontendCommand extends Command
{
    protected $signature = 'fahoba:frontend {package : vendor/name of the frontend package}';

    protected $description = 'Checks a frontend package and prints the directory of its built app';

    public function handle(): int
    {
        $result = FrontendPackage::resolve((string) $this->argument('package'));

        if (! str_starts_with($result, '/')) {
            $this->error($result);

            return self::FAILURE;
        }

        $this->line($result);

        return self::SUCCESS;
    }
}
