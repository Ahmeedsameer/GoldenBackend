<?php

namespace App\Modules\Convention\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Modules\Convention\Services\NotificationService;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(private NotificationService $notificationService) {}

    /** GET /api/notifications */
    public function index()
    {
        $perPage = request()->integer('per_page', 15);

        return response()->json([
            'message'      => 'تم جلب الإشعارات بنجاح',
            'unread_count' => $this->notificationService->unreadCount(auth()->id()),
            'data'         => $this->notificationService->forUser(auth()->id(), $perPage),
        ]);
    }

    /** GET /api/notifications/unread-count */
    public function unreadCount()
    {
        return response()->json([
            'unread_count' => $this->notificationService->unreadCount(auth()->id()),
        ]);
    }

    /** PUT /api/notifications/{id}/read */
    public function markRead(string $id)
    {
        $notification = $this->notificationService->markAsRead(auth()->id(), (int) $id);

        return response()->json([
            'message' => 'تم تعليم الإشعار كمقروء',
            'data'    => $notification,
        ]);
    }

    /** PUT /api/notifications/read-all */
    public function markAllRead()
    {
        $count = $this->notificationService->markAllAsRead(auth()->id());

        return response()->json([
            'message' => 'تم تعليم كل الإشعارات كمقروءة',
            'count'   => $count,
        ]);
    }

    /** GET /api/notifications/vapid-key — the VAPID public key for PushManager.subscribe */
    public function vapidKey()
    {
        return response()->json([
            'public_key' => $this->notificationService->webPush()->publicKey(),
        ]);
    }

    /** POST /api/notifications/subscribe — store a Web Push subscription */
    public function subscribe(Request $request)
    {
        $data = $request->validate([
            'endpoint'   => ['required', 'string'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth'   => ['required', 'string'],
        ]);

        $sub = PushSubscription::store(
            auth()->id(),
            $data['endpoint'],
            $data['keys']['p256dh'],
            $data['keys']['auth']
        );

        return response()->json([
            'message' => 'تم الاشتراك في الإشعارات بنجاح',
            'data'    => ['id' => $sub->id],
        ]);
    }

    /** POST /api/notifications/unsubscribe — remove a Web Push subscription */
    public function unsubscribe(Request $request)
    {
        $data = $request->validate(['endpoint' => ['required', 'string']]);

        PushSubscription::where('endpoint_hash', hash('sha256', $data['endpoint']))
            ->where('user_id', auth()->id())
            ->delete();

        return response()->json(['message' => 'تم إلغاء الاشتراك']);
    }

    /**
     * POST /api/notifications/test — send a sample notification to the current user
     * (saved in the Notification Center, broadcast, AND sent via Web Push).
     */
    public function test()
    {
        $report = $this->notificationService->sendToUser(
            auth()->id(),
            'test',
            'Test Notification',
            'This is a sample Web Push notification confirming your setup works end-to-end.',
            ['datetime' => now()->toDateTimeString()]
        );

        return response()->json([
            'message' => 'تم إرسال إشعار تجريبي',
            'push'    => $report,
            'hint'    => $report['configured']
                ? ($report['subscriptions'] === 0
                    ? 'مُهيأ لكن لا يوجد اشتراك لهذا المستخدم — افتح التطبيق واسمح بالإشعارات أولاً.'
                    : "تم الإرسال إلى {$report['sent']} اشتراك.")
                : 'مفاتيح VAPID غير مُهيأة. تم حفظ الإشعار في مركز الإشعارات فقط.',
        ]);
    }
}
