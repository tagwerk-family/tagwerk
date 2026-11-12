<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;

/**
 * The first account on an empty server: it belongs to no household and sets
 * up all of them in the admin panel. The operator's own family is a household
 * like any other, with a separate parent login.
 */
final class CreateOperatorCommand extends Command
{
    protected $signature = 'fahoba:create-operator
        {name : Shown in the admin panel}
        {email : Login of the operator}';

    protected $description = 'Creates an operator account that manages all households';

    public function handle(): int
    {
        // Asked, never an argument: it would otherwise end up in the shell history.
        $secret = password('Password', required: true);

        $validator = Validator::make(
            ['email' => $this->argument('email'), 'password' => $secret],
            [
                'email' => ['required', 'email', 'unique:users,email'],
                'password' => ['required', 'string', 'min:12'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'name' => (string) $this->argument('name'),
            'email' => (string) $this->argument('email'),
            'password' => $secret,
        ]);
        $user->forceFill(['is_operator' => true, 'email_verified_at' => now()])->save();

        $this->info("Operator {$user->email} created. Households are set up in /admin under Betrieb.");

        return self::SUCCESS;
    }
}
