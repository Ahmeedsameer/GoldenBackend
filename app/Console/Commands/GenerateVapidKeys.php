<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class GenerateVapidKeys extends Command
{
    protected $signature = 'webpush:vapid';

    protected $description = 'Generate a VAPID key pair for Web Push and print the .env lines';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();

        $this->info('VAPID key pair generated. Add these to your backend .env:');
        $this->line('');
        $this->line('VAPID_SUBJECT=mailto:admin@alpha.com');
        $this->line('VAPID_PUBLIC_KEY=' . $keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY=' . $keys['privateKey']);
        $this->line('');
        $this->warn('The PUBLIC key also goes into the Angular environment (vapidPublicKey).');

        return self::SUCCESS;
    }
}
