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

    /**
     * Send the same notification to a set of users (Notification Center +
     * broadcast + a single batched Web Push). De-duplicates the id list.
     * Reused by inventory alerts to reach admins + the branch manager at once.
     *
     * @return int number of notifications created
     */
    public function notify(array $userIds, string $type, string $title, string $message, array $data = []): int
    {
        $ids = array_values(array_unique(array_filter($userIds)));

        foreach ($ids as $userId) {
            $this->persistAndBroadcast((int) $userId, $type, $title, $message, $data);
        }

        // Standard Web Push (no-op when VAPID not configured)
        $this->webPush->sendToUsers($ids, $title, $message, array_merge($data, ['type' => $type]));

        return count($ids);
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

    /**
     * Send an admin notification that represents an ongoing UNRESOLVED queue
     * of work (e.g. "N batches need pricing") rather than a one-off event —
     * refreshes the existing unread notification of the same type instead of
     * stacking a new row every time the queue changes size, so an admin who
     * hasn't acted yet sees "8 batches" (the current total), never a pile of
     * stale "3 batches" / "5 batches" duplicates for the same unresolved
     * state. Once the admin reads it (existing markAsRead/markAllAsRead —
     * e.g. by clicking it), the next call for that type starts a fresh row,
     * which is the correct signal that new work arrived after they last
     * looked. Reuses the exact same Notification table/broadcast/Web Push
     * plumbing as notifyAdmins() — no second notification store.
     *
     * @return int number of admins notified (created or refreshed)
     */
    public function notifyAdminsAggregated(string $type, string $title, string $message, array $data = []): int
    {
        $adminIds = User::where('role', 'admin')->pluck('id');

        foreach ($adminIds as $adminId) {
            $this->upsertUnreadAndBroadcast((int) $adminId, $type, $title, $message, $data);
        }

        $this->webPush->sendToUsers($adminIds->all(), $title, $message, array_merge($data, ['type' => $type]));

        return $adminIds->count();
    }

    private function upsertUnreadAndBroadcast(int $userId, string $type, string $title, string $message, array $data): Notification
    {
        $existing = Notification::where('user_id', $userId)
            ->where('type', $type)
            ->whereNull('read_at')
            ->latest('id')
            ->first();

        if ($existing) {
            $existing->update(['title' => $title, 'message' => $message, 'data' => $data ?: null]);
            $notification = $existing;
        } else {
            $notification = Notification::create([
                'user_id' => $userId, 'type' => $type, 'title' => $title, 'message' => $message, 'data' => $data ?: null,
            ]);
        }

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
