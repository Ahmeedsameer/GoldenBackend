<?php

namespace App\Modules\Convention\Services;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Standard Web Push (VAPID) sender — no Firebase.
 *
 * Degrades gracefully to a no-op when VAPID keys are not configured, so the
 * app keeps working (Notification Center + broadcasting) without push.
 */
class WebPushService
{
    public function isConfigured(): bool
    {
        return (bool) config('webpush.vapid.public_key') && (bool) config('webpush.vapid.private_key');
    }

    public function publicKey(): ?string
    {
        return config('webpush.vapid.public_key');
    }

    /**
     * Send a Web Push notification to every subscription of the given users.
     *
     * @return array{configured:bool,subscriptions:int,sent:int,failed:int,errors:array<string>}
     */
    public function sendToUsers(array $userIds, string $title, string $body, array $data = []): array
    {
        $report = ['configured' => false, 'subscriptions' => 0, 'sent' => 0, 'failed' => 0, 'errors' => []];

        if (empty($userIds)) {
            return $report;
        }

        if (! $this->isConfigured()) {
            Log::info('[WebPush] Not configured — skipping push.');
            $report['errors'][] = 'VAPID keys not configured.';
            return $report;
        }
        $report['configured'] = true;

        $subscriptions = PushSubscription::whereIn('user_id', $userIds)->get();
        $report['subscriptions'] = $subscriptions->count();
        if ($subscriptions->isEmpty()) {
            $report['errors'][] = 'No push subscriptions registered for the target user(s).';
            return $report;
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject'    => config('webpush.vapid.subject'),
                'publicKey'  => config('webpush.vapid.public_key'),
                'privateKey' => config('webpush.vapid.private_key'),
            ],
        ]);

        $payload = json_encode([
            'title' => $title,
            'body'  => $body,
            'data'  => $data,
        ]);

        foreach ($subscriptions as $sub) {
            $webPush->queueNotification(
                Subscription::create([
                    'endpoint'        => $sub->endpoint,
                    'publicKey'       => $sub->public_key,
                    'authToken'       => $sub->auth_token,
                    'contentEncoding' => 'aes128gcm',
                ]),
                $payload
            );
        }

        foreach ($webPush->flush() as $result) {
            if ($result->isSuccess()) {
                $report['sent']++;
            } else {
                $report['failed']++;
                $report['errors'][] = $result->getReason();

                // 404 / 410 → the subscription is gone; remove it.
                if ($result->isSubscriptionExpired()) {
                    PushSubscription::where('endpoint_hash', hash('sha256', $result->getEndpoint()))->delete();
                }
            }
        }

        return $report;
    }
}
