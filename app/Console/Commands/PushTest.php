<?php

namespace App\Console\Commands;

use App\Models\PushSubscription;
use App\Models\User;
use App\Modules\Convention\Services\NotificationService;
use Illuminate\Console\Command;

class PushTest extends Command
{
    protected $signature = 'push:test {--user= : Target a specific user id (default: all admins)}';

    protected $description = 'Send a sample Web Push notification to verify the setup';

    public function handle(NotificationService $notifications): int
    {
        $webPush = $notifications->webPush();

        $this->line('');
        $this->info('── Web Push configuration ───────────────────────');
        $this->line('  VAPID public  : ' . (config('webpush.vapid.public_key') ? 'set' : '(not set)'));
        $this->line('  VAPID private : ' . (config('webpush.vapid.private_key') ? 'set' : '(not set)'));
        $this->line('  configured    : ' . ($webPush->isConfigured() ? 'YES' : 'NO'));

        if (! $webPush->isConfigured()) {
            $this->warn('VAPID keys not set. Run `php artisan webpush:vapid` and add them to .env.');
        }

        $users = $this->option('user')
            ? User::where('id', $this->option('user'))->pluck('id')
            : User::where('role', 'admin')->pluck('id');

        if ($users->isEmpty()) {
            $this->error('No target users found.');
            return self::FAILURE;
        }

        $subCount = PushSubscription::whereIn('user_id', $users)->count();
        $this->info('── Targets ──────────────────────────────────────');
        $this->line('  users         : ' . $users->implode(', '));
        $this->line('  subscriptions : ' . $subCount);

        $this->info('── Sending ──────────────────────────────────────');
        $totals = ['sent' => 0, 'failed' => 0];
        foreach ($users as $userId) {
            $report = $notifications->sendToUser(
                $userId,
                'test',
                'Test Notification',
                'This is a sample Web Push notification confirming your setup works end-to-end.',
                ['datetime' => now()->toDateTimeString()]
            );
            $totals['sent']   += $report['sent'];
            $totals['failed'] += $report['failed'];
            foreach ($report['errors'] as $err) {
                $this->warn("  user {$userId}: {$err}");
            }
        }

        $this->info('── Result ───────────────────────────────────────');
        $this->line('  pushes sent   : ' . $totals['sent']);
        $this->line('  pushes failed : ' . $totals['failed']);
        $this->line('  (a Notification Center entry + broadcast was emitted for every target)');
        $this->line('');

        return self::SUCCESS;
    }
}
