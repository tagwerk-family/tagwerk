<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

final class GenerateVapidKeys extends Command
{
    protected $signature = 'fahoba:vapid-keys';

    protected $description = 'Generates a VAPID key pair for Web Push';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();

        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);

        $this->newLine();
        $this->warn(
            'Generate once and keep safe. Changing the public key '
            .'invalidates all existing subscriptions.',
        );

        return self::SUCCESS;
    }
}
