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
 * The owner accepted or rejected a request.
 *
 * Broadcast on the customer's private channel only — the payload names the
 * space and the slot, which is booking data.
 */
class BookingStatusChanged implements ShouldBroadcast
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
            new PrivateChannel('user.'.$this->notification->user_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'booking.status_changed';
    }

    public function broadcastWith(): array
    {
        return $this->notificationPayload($this->notification);
    }
}
