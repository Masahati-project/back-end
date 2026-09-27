<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function index(Request $request)
    {
        $bookings = Booking::whereHas('space', function ($query) use ($request) {
            $query->where('user_id', $request->user()->id);
        })->latest()->get();

        return response()->json(['bookings' => $bookings]);
    }

    public function updateStatus(Request $request, $id)
    {
        $booking = Booking::whereHas('space', function ($query) use ($request) {
            $query->where('user_id', $request->user()->id);
        })->where('id', $id)->firstOrFail();

        $status = $request->input('status') === 'confirmed' ? 'confirmed' : 'cancelled';
        $booking->update(['status' => $status]);

        return response()->json([
            'message' => 'تم تحديث حالة الحجز بنجاح.',
            'booking' => $booking
        ]);
    }
}