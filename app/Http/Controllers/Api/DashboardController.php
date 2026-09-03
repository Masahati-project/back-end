<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Favorite;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function stats(Request $request)
    {
        $userId = Auth::id();
        $now = Carbon::now();

        $upcomingBookingsCount = Booking::where('user_id', $userId)
            ->where('start_datetime', '>', $now)
            ->where('status', 'confirmed')
            ->count();

        $totalAttendedHours = Booking::where('user_id', $userId)
            ->where('status', 'completed')
            ->get()
            ->sum(function ($booking) {
                return Carbon::parse($booking->start_datetime)
                    ->diffInMinutes(Carbon::parse($booking->end_datetime)) / 60;
            });

        $favoriteSpacesCount = Favorite::where('user_id', $userId)->count();

        $bookedHoursThisMonth = Booking::where('user_id', $userId)
            ->whereMonth('start_datetime', $now->month)
            ->whereYear('start_datetime', $now->year)
            ->whereIn('status', ['confirmed', 'completed'])
            ->get()
            ->sum(function ($booking) {
                return Carbon::parse($booking->start_datetime)
                    ->diffInMinutes(Carbon::parse($booking->end_datetime)) / 60;
            });

        return response()->json([
            'upcoming_bookings_count' => $upcomingBookingsCount,
            'total_hours' => round($totalAttendedHours, 1),
            'favorite_spaces_count' => $favoriteSpacesCount,
            'booked_hours_this_month' => round($bookedHoursThisMonth, 1),
        ], 200);
    }

    public function upcomingBooking(Request $request)
    {
        $bookings = Booking::with(['unit.workspace.images'])
            ->where('user_id', Auth::id())
            ->where('start_datetime', '>', now())
            ->where('status', 'confirmed')
            ->orderBy('start_datetime')
            ->get()
            ->map(function ($booking) {
                return [
                    'title' => $booking->unit->workspace->name,
                    'image' => $booking->unit->workspace->images->first()?->image_url,
                    'date' => $booking->start_datetime->format('Y-m-d'),
                    'time' => $booking->start_datetime->format('H:i'),
                ];
            });

        return response()->json(['data' => $bookings], 201);
    }

    public function bookings(Request $request)
    {
        $bookings = Booking::with(['unit.workspace.images'])
            ->where('user_id', Auth::id())
            ->orderBy('start_datetime')
            ->get()
            ->map(function ($booking) {
                return [
                    'booking_id' => $booking->id,
                    'space_name' => $booking->unit->workspace->name,
                    'image' => $booking->unit->workspace->images->first()?->image_url,
                    'date' => $booking->start_datetime->format('Y-m-d'),
                    'time_from' => $booking->start_datetime->format('H:i'),
                    'time_to' => $booking->end_datetime->format('H:i'),
                    'status' => $booking->status
                ];
            });

        return response()->json(['data' => $bookings], 201);
    }

    public function favoriteSpaces(Request $request)
    {
        $favorites = Favorite::where('user_id', Auth::id())
            ->with([
                'workspace' => function ($q) {
                    $q->withAvg('reviews', 'rating')
                        ->with(['images', 'units.pricing']);
                },
            ])
            ->orderBy('created_at')->get()
            ->map(function ($favorite) {
                $workspace = $favorite->workspace;
                return [
                    'space_id' => $workspace->id,
                    'title' => $workspace->name,
                    'image' => $workspace->images->first()?->image_url,
                    'rating' => round($workspace->reviews_avg_rating ?? 0, 1),
                    'location' => trim($workspace->address . '، ' . $workspace->city, '، '),
                    'price' => $workspace->units->first()?->pricing->first()?->price
                ];
            });

        return response()->json(['data' => $favorites], 201);
    }

    public function toggle(Request $request)
    {
        $request->validate([
            'space_id' => 'required|exists:workspaces,id'
        ]);

        $result = $request->user()->favoriteWorkspaces()->toggle($request->space_id);

        $isFavorited = count($result['attached']) > 0;

        return response()->json([
            'message' => $isFavorited ? 'تمت الإضافة للمفضلة' : 'تمت الإزالة من المفضلة',
            'is_favorited' => $isFavorited,
        ], $isFavorited ? 201 : 200);
    }
}
