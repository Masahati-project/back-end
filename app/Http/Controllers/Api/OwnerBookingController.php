<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\ImageService;
use App\Services\NotificationService;
use App\Traits\OwnerAuthorization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OwnerBookingController extends Controller
{
    use OwnerAuthorization;

    /**
     * GET /api/owner/bookings
     *
     * Pending rows are included on purpose: the owner cannot approve a booking
     * it cannot see. `data` is the agreed shape; `bookings` is kept for the
     * screens that still read the older key.
     */
    public function index()
    {
        $this->ensureOwnerRole();

        $bookings = Booking::with(['unit.workspace.images', 'user'])
            ->whereHas('unit.workspace', function ($q) {
                $q->where('owner_id', Auth::id());
            })
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($booking) {
                return $this->formatBookingResponse($booking);
            })
            ->values();

        return response()->json([
            'data' => $bookings,
            'bookings' => $bookings,
        ]);
    }

    /**
     * PATCH /api/owner/bookings/{booking}/status
     *
     * Only the owning owner may decide. The transition is idempotent and only
     * `pending` may leave its state, so `confirmed`/`cancelled` are terminal.
     */
    public function updateStatus(Request $request, string $id)
    {
        $this->ensureOwnerRole();
        $booking = $this->ensureOwnsBooking($id);

        $validated = $request->validate([
            'status' => 'required|in:confirmed,cancelled',
        ]);

        $target = $validated['status'];

        // Idempotent: repeated Accept/Reject returns the same state and does
        // not send a second notification.
        if ($booking->status === $target) {
            return $this->statusResponse($booking, $target);
        }

        if ($booking->status !== 'pending') {
            return response()->json([
                'message' => 'لا يمكن تغيير حالة هذا الحجز.',
            ], 422);
        }

        $booking->update(['status' => $target]);

        // Triggers #2 / #3 fire after the row is saved.
        NotificationService::createForBookingDecision($booking);

        return $this->statusResponse($booking, $target);
    }

    private function statusResponse(Booking $booking, string $status)
    {
        $booking->loadMissing(['unit.workspace.images', 'user']);

        $payload = $this->formatBookingResponse($booking);

        return response()->json([
            'message' => $status === 'confirmed' ? 'تم تأكيد الحجز.' : 'تم إلغاء الحجز.',
            'data' => [
                'booking' => $payload,
            ],
            // Kept for the screens that still read the top-level key.
            'booking' => $payload,
        ]);
    }

    private function formatBookingResponse(Booking $booking)
    {
        $unit = $booking->unit;
        $space = $unit?->workspace;

        $hours = ($booking->start_datetime !== null && $booking->end_datetime !== null)
            ? round($booking->start_datetime->diffInMinutes($booking->end_datetime) / 60, 2)
            : null;

        $spaceImage = $space !== null
            ? ImageService::url($space->images->first()?->image_url)
            : null;

        $price = $booking->total_price !== null
            ? round((float) $booking->total_price, 2)
            : null;

        return [
            'id' => $booking->id,
            'booking_id' => $booking->id,
            'ref' => $booking->ref,
            'spaceId' => $space?->id,
            'space_id' => $space?->id,
            'spaceName' => $space?->title,
            'space_name' => $space?->title,
            'title' => $space?->title,
            'image' => $spaceImage,
            'date' => $booking->start_datetime?->format('Y-m-d'),
            'time' => $booking->start_datetime !== null && $booking->end_datetime !== null
                ? $booking->start_datetime->format('H:i:s').' - '.$booking->end_datetime->format('H:i:s')
                : null,
            'timeFrom' => $booking->start_datetime?->format('H:i:s'),
            'time_from' => $booking->start_datetime?->format('H:i:s'),
            'start_time' => $booking->start_datetime?->format('H:i:s'),
            'timeTo' => $booking->end_datetime?->format('H:i:s'),
            'time_to' => $booking->end_datetime?->format('H:i:s'),
            'end_time' => $booking->end_datetime?->format('H:i:s'),
            'hours' => $hours,
            'duration_hours' => $hours,
            'price' => $price,
            'cost' => $price,
            'total_price' => $price,
            'notes' => $booking->notes,
            'customer' => $booking->user?->full_name,
            'customer_name' => $booking->user?->full_name,
            'status' => $booking->status,
            'created_at' => $booking->created_at?->toIso8601String(),
        ];
    }
}
