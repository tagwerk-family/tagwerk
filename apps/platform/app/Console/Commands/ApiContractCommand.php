<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\ApiContract;
use Illuminate\Console\Command;

/** Writes the routes frontends may rely on into `routes/api-contract.txt`. */
final class ApiContractCommand extends Command
{
    protected $signature = 'fahoba:api-contract';

    protected $description = 'Writes the API routes community frontends may rely on';

    public function handle(): int
    {
        file_put_contents(ApiContract::path(), ApiContract::build());

        $this->info('API contract written to '.ApiContract::path().'.');

        return self::SUCCESS;
    }
}
