<?php

namespace App\Jobs;

use App\Modules\Convention\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Notifies ONE employee that their weekly schedule was published / updated /
 * republished / cancelled. Dispatched once per affected employee (never one
 * HTTP request per employee — the whole week is saved/published in a single
 * request; this job only fans out the notification, and runs on the queue).
 */
class SendScheduleNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $userId,
        public string $event, // published | updated | republished | cancelled
        public string $weekFrom,
        public string $weekTo,
    ) {}

    public function handle(NotificationService $notifications): void
    {
        $titles = [
            'published'   => 'تم نشر جدولك الأسبوعي',
            'updated'     => 'تم تحديث جدولك الأسبوعي',
            'republished' => 'تم إعادة نشر جدولك الأسبوعي',
            'cancelled'   => 'تم إلغاء جدولك الأسبوعي',
        ];
        $messages = [
            'published'   => "جدول عملك للأسبوع {$this->weekFrom} → {$this->weekTo} أصبح متاحًا الآن.",
            'updated'     => "تم تحديث جدول عملك للأسبوع {$this->weekFrom} → {$this->weekTo}.",
            'republished' => "تمت إعادة نشر جدول عملك للأسبوع {$this->weekFrom} → {$this->weekTo}.",
            'cancelled'   => "تم إلغاء جدول عملك للأسبوع {$this->weekFrom} → {$this->weekTo}.",
        ];

        $notifications->sendToUser(
            $this->userId,
            'schedule',
            $titles[$this->event] ?? 'تحديث الجدول الأسبوعي',
            $messages[$this->event] ?? '',
            ['type' => 'schedule', 'week_from' => $this->weekFrom, 'week_to' => $this->weekTo, 'event' => $this->event, 'route' => '/dashboard/my-schedule'],
        );
    }
}
