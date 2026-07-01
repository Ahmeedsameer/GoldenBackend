<?php

namespace App\Events;

use App\Models\Notification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotificationCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Notification $notification,
        public int $unreadCount
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('notifications.' . $this->notification->user_id)];
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    public function broadcastWith(): array
    {
        return [
            'notification' => [
                'id'         => $this->notification->id,
                'type'       => $this->notification->type,
                'title'      => $this->notification->title,
                'message'    => $this->notification->message,
                'data'       => $this->notification->data,
                'read_at'    => $this->notification->read_at,
                'created_at' => $this->notification->created_at,
            ],
            'unread_count' => $this->unreadCount,
        ];
    }
}
