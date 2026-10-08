<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Traits\OwnerAuthorization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OwnerBookingController extends Controller
{
    use OwnerAuthorization;


    public function index()
    {
        $this->ensureOwnerRole();

        $bookings = Booking::whereHas('unit.workspace', function ($q) {
            $q->where('owner_id', Auth::id());
        })->orderByDesc('created_at')->get()->map(function ($booking) {
            return $this->formatBookingResponse($booking);
        });

        return response()->json(['bookings' => $bookings]);
    }

    public function updateStatus(Request $request, string $id)
    {
        $this->ensureOwnerRole();
        $booking = $this->ensureOwnsBooking($id);

        $validated = $request->validate([
            'status' => 'required|in:confirmed,cancelled',
        ]);

        $booking->update(['status' => $validated['status']]);

        return response()->json([
            'message' => $validated['status'] === 'confirmed' ? 'تم تأكيد الحجز.' : 'تم إلغاء الحجز.',
            'booking' => $this->formatBookingResponse($booking),
        ]);
    }

    private function formatBookingResponse(Booking $booking)
    {
        $unit = $booking->unit;
        $space = $unit->workspace;

        $hours = $booking->start_datetime->diffInMinutes($booking->end_datetime) / 60;
        $spaceImage = $space->images->first()?->image_url;

        return [
            'id' => $booking->id,
            'booking_id' => $booking->id,
            'spaceId' => $space->id,
            'space_id' => $space->id,
            'spaceName' => $space->title,
            'space_name' => $space->title,
            'title' => $space->title,
            'image' => $spaceImage,
            'date' => $booking->start_datetime->format('Y-m-d'),
            'time' => $booking->start_datetime->format('H:i:s') . ' - ' . $booking->end_datetime->format('H:i:s'),
            'timeFrom' => $booking->start_datetime->format('H:i:s'),
            'time_from' => $booking->start_datetime->format('H:i:s'),
            'start_time' => $booking->start_datetime->format('H:i:s'),
            'timeTo' => $booking->end_datetime->format('H:i:s'),
            'time_to' => $booking->end_datetime->format('H:i:s'),
            'end_time' => $booking->end_datetime->format('H:i:s'),
            'hours' => round($hours, 2),
            'duration_hours' => round($hours, 2),
            'price' => $booking->total_price,
            'cost' => $booking->total_price,
            'total_price' => $booking->total_price,
            'customer' => $booking->user->full_name,
            'customer_name' => $booking->user->full_name,
            'status' => $booking->status,
        ];
    }

    private function formatTime(Booking $booking)
    {
        if (!$booking->start_time || !$booking->end_time) {
            return null;
        }
        $from = date('H:i:s', strtotime($booking->start_time));
        $to = date('H:i:s', strtotime($booking->end_time));
        return "{$from} - {$to}";
    }
}
