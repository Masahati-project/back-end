<?php

namespace App\Events\Concerns;

use App\Models\Notification;

/**
 * The wire shape shared by the booking broadcast events.
 *
 * Both events carry exactly the notification row that was just written, plus its
 * `id`, so the client can render the toast and then dedupe it against the same
 * row it will read back from `GET /api/notifications`.
 */
trait FormatsBroadcastNotification
{
    public function notificationPayload(Notification $notification): array
    {
        $data = $notification->data ?? [];

        return [
            'id' => $notification->id,
            'notification_id' => $notification->id,
            'type' => $notification->type,
            'title' => $notification->title,
            'message' => $notification->message,
            'body' => $notification->message,
            'space_id' => $data['space_id'] ?? null,
            'booking_id' => $data['booking_id'] ?? null,
            'space_name' => $data['space_name'] ?? null,
            'customer_name' => $data['customer_name'] ?? null,
            'link' => $notification->link,
            'data' => $data,
            'created_at' => $notification->created_at?->toDateTimeString(),
        ];
    }
}
