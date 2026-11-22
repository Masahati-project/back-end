<?php

namespace App\Services;

use App\Events\BookingRequested;
use App\Events\BookingStatusChanged;
use App\Models\Booking;
use App\Models\Notification;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    /**
     * Create a new class instance.
     */
    public function __construct() {}

    /**
     * Write one notification row.
     *
     * `message` is the human-readable text every screen already renders.
     * `title`, `link` and `data` are optional context the newer clients read;
     * older call sites keep working because they only pass the first three
     * arguments.
     */
    public static function create(
        int $userId,
        string $type,
        string $message,
        ?string $title = null,
        ?string $link = null,
        ?array $data = null
    ): Notification {
        return Notification::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'link' => $link,
            'data' => $data,
            'is_read' => false,
        ]);
    }

    public static function createForSpecialRequest(int $userId, string $action, string $requestTitle): Notification
    {
        $map = [
            'created' => ['special_request_new',    "طلب جديد في السوق: «{$requestTitle}» — قدّم عرضك."],
            'accepted' => ['special_request_accepted', "تم قبول طلبك الخاص: {$requestTitle}"],
            'rejected' => ['special_request_closed',  "تم رفض طلبك الخاص: {$requestTitle}"],
            'closed' => ['special_request_closed',  "تم إغلاق طلبك الخاص: {$requestTitle}"],
        ];

        [$type, $text] = $map[$action] ?? ['special_request', 'تحديث طلب خاص'];

        return self::create($userId, $type, $text);
    }

    public static function createForOffer(int $userId, string $action, string $workspaceName): Notification
    {
        $map = [
            'received' => ['special_request_offer',         "عرض جديد على طلبك «{$workspaceName}»"],
            'accepted' => ['special_request_offer_accepted', "تم قبول عرضك على «{$workspaceName}»"],
            'rejected' => ['special_request_offer_rejected', "تم رفض عرضك على «{$workspaceName}»"],
            'closed' => ['special_request_offer_closed',   "أُغلق الطلب «{$workspaceName}» دون الرد على عرضك"],
        ];

        [$type, $text] = $map[$action] ?? ['special_request_offer', 'تحديث عرض'];

        return self::create($userId, $type, $text);
    }

    /**
     * Trigger #1: a customer asked to book one of the owner's spaces.
     *
     * Called after the booking transaction has committed, never inside it, so
     * a rollback cannot leave an orphan notification behind.
     */
    public static function createForBookingRequest(Booking $booking): ?Notification
    {
        $booking->loadMissing(['unit.workspace', 'user']);

        $ownerId = $booking->unit?->workspace?->owner_id;

        if ($ownerId === null) {
            return null;
        }

        $data = self::bookingPayload($booking);

        $text = sprintf(
            'طلب حجز على «%s» من %s',
            $data['space_name'],
            $data['customer_name']
        );

        $notification = self::create(
            (int) $ownerId,
            'booking_request',
            $text,
            'طلب حجز جديد',
            '/dashboard/space-owner?tab=bookings&booking='.$booking->id,
            $data
        );

        self::broadcastSafely(new BookingRequested($notification));

        return $notification;
    }

    /**
     * Triggers #2 and #3: the owner accepted or rejected the request.
     *
     * The link and the structured payload carry the booking id; the Accept /
     * Reject buttons deliberately stay on the booking screen, not in the
     * notification, so a stale tap can never confirm a slot that is no longer
     * free.
     */
    public static function createForBookingDecision(Booking $booking): ?Notification
    {
        $booking->loadMissing(['unit.workspace', 'user']);

        if (! in_array($booking->status, ['confirmed', 'rejected'], true)) {
            return null;
        }

        $data = self::bookingPayload($booking);

        if ($booking->status === 'confirmed') {
            $type = 'booking_confirmed';
            $title = 'تم قبول حجزك';
            $text = sprintf('تم تأكيد حجزك على «%s»', $data['space_name']);
        } else {
            $type = 'booking_rejected';
            $title = 'تم رفض حجزك';
            $text = sprintf('تم رفض حجزك على «%s»', $data['space_name']);
        }

        // The customer is sent to the space page, where the booking lives, so a
        // stale toast cannot act on a slot that has moved on.
        $notification = self::create(
            (int) $booking->user_id,
            $type,
            $text,
            $title,
            '/ads/'.$data['space_id'],
            $data
        );

        self::broadcastSafely(new BookingStatusChanged($notification));

        return $notification;
    }

    /**
     * The payload shape both sides of the booking flow agree on.
     */
    private static function bookingPayload(Booking $booking): array
    {
        $space = $booking->unit?->workspace;
        $start = $booking->start_datetime;
        $end = $booking->end_datetime;

        $hours = ($start !== null && $end !== null)
            ? round($start->diffInMinutes($end) / 60, 2)
            : null;

        $price = $booking->total_price !== null
            ? round((float) $booking->total_price, 2)
            : null;

        return [
            'booking_id' => $booking->id,
            'space_id' => $space?->id,
            'space_name' => $space?->title ?? 'المساحة',
            'customer_name' => $booking->user?->full_name ?? 'عميل',
            'date' => $start?->format('Y-m-d'),
            'time_from' => $start?->format('H:i:s'),
            'time_to' => $end?->format('H:i:s'),
            'hours' => $hours,
            'price' => $price,
            'status' => $booking->status,
        ];
    }

    /**
     * Fire a broadcast event without letting a socket problem fail the request.
     *
     * The notification row is the source of truth and has already been written
     * by the time this runs. A broadcaster that is down — or a queue that cannot
     * accept the job — must not turn a successful booking into a 500, so the
     * failure is logged and swallowed. The client still gets the row on its next
     * `GET /api/notifications` poll.
     */
    private static function broadcastSafely(object $event): void
    {
        try {
            event($event);
        } catch (\Throwable $e) {
            Log::warning('Booking broadcast failed; notification row was still created.', [
                'event' => $event::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
