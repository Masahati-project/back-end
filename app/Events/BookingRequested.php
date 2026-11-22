<?php

namespace App\Events;

use App\Events\Concerns\FormatsBroadcastNotification;
use App\Models\Notification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A customer asked to book one of the owner's spaces.
 *
 * Broadcast on the owner's private channel only: the payload carries the
 * customer's name and the slot, which must never reach a public channel.
 */
class BookingRequested implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels, FormatsBroadcastNotification;

    public function __construct(public Notification $notification)
    {
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('owner.'.$this->notification->user_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'booking.requested';
    }

    public function broadcastWith(): array
    {
        return $this->notificationPayload($this->notification);
    }
}
