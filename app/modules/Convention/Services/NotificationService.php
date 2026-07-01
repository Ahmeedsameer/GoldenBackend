<?php

namespace App\Modules\Convention\Services;

use App\Events\NotificationCreated;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    public function __construct(private WebPushService $webPush) {}

    /**
     * Send a notification to every admin user:
     *  - persisted in the Notification Center (DB)
     *  - broadcast in real time (Reverb) for instant UI updates
     *  - delivered as a standard Web Push (VAPID) browser notification
     *
     * @return int number of notifications created
     */
    public function notifyAdmins(string $type, string $title, string $message, array $data = []): int
    {
        $adminIds = User::where('role', 'admin')->pluck('id');

        foreach ($adminIds as $adminId) {
            $this->persistAndBroadcast($adminId, $type, $title, $message, $data);
        }

        // Standard Web Push (no-op when VAPID not configured)
        $this->webPush->sendToUsers($adminIds->all(), $title, $message, array_merge($data, ['type' => $type]));

        return $adminIds->count();
    }

    /**
     * Send a single notification to one user (Notification Center + broadcast + Web Push).
     * Used by the push test endpoint / command.
     *
     * @return array the Web Push delivery report
     */
    public function sendToUser(int $userId, string $type, string $title, string $message, array $data = []): array
    {
        $this->persistAndBroadcast($userId, $type, $title, $message, $data);

        return $this->webPush->sendToUsers([$userId], $title, $message, array_merge($data, ['type' => $type]));
    }

    private function persistAndBroadcast(int $userId, string $type, string $title, string $message, array $data): Notification
    {
        $notification = Notification::create([
            'user_id' => $userId,
            'type'    => $type,
            'title'   => $title,
            'message' => $message,
            'data'    => $data ?: null,
        ]);

        // Real-time fan-out (graceful if broadcasting/Reverb is not running)
        try {
            broadcast(new NotificationCreated($notification, $this->unreadCount($userId)));
        } catch (\Throwable $e) {
            Log::warning('[broadcast] ' . $e->getMessage());
        }

        return $notification;
    }

    public function webPush(): WebPushService
    {
        return $this->webPush;
    }

    public function forUser(int $userId, int $perPage = 15)
    {
        return Notification::where('user_id', $userId)
            ->latest()
            ->paginate($perPage);
    }

    public function unreadCount(int $userId): int
    {
        return Notification::where('user_id', $userId)
            ->whereNull('read_at')
            ->count();
    }

    public function markAsRead(int $userId, int $notificationId): Notification
    {
        $notification = Notification::where('user_id', $userId)->findOrFail($notificationId);
        $notification->markAsRead();

        return $notification;
    }

    public function markAllAsRead(int $userId): int
    {
        return Notification::where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }
}
