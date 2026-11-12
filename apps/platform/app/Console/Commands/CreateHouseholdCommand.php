<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\HouseholdOnboarding;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;

/**
 * Sets up a household from the shell. Usually households are created in the
 * admin panel by the operator (`fahoba:create-operator`); this is the way
 * without one.
 */
final class CreateHouseholdCommand extends Command
{
    protected $signature = 'fahoba:create-household
        {name : Name of the household, e.g. "Familie Muster"}
        {parent : Name of the first parent}
        {email : Login of the first parent}
        {--timezone=Europe/Vienna : Time zone until the household sets its location}';

    protected $description = 'Creates a household with its first parent account';

    public function handle(HouseholdOnboarding $onboarding): int
    {
        // Asked, never an argument: it would otherwise end up in the shell history.
        $secret = password('Password', required: true);

        $validator = Validator::make(
            [
                'email' => $this->argument('email'),
                'password' => $secret,
                'timezone' => $this->option('timezone'),
            ],
            [
                'email' => ['required', 'email', 'unique:users,email'],
                'password' => ['required', 'string', 'min:10'],
                'timezone' => ['required', 'timezone:all'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = $onboarding->create(
            (string) $this->argument('name'),
            (string) $this->option('timezone'),
            (string) $this->argument('parent'),
            (string) $this->argument('email'),
            $secret,
        );

        $this->info("Household {$user->memberships()->sole()->household_id} created, login: {$user->email}");

        return self::SUCCESS;
    }
}
