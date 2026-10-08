<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Dispute;
use Illuminate\Http\Request;

class AdminBookingsController extends Controller
{
    /**
     * List bookings
     * GET /api/admin/bookings
     */
    public function index(Request $request)
    {
        $query = Booking::with('user', 'unit.workspace');

        if ($request->has('q')) {
            $q = $request->q;
            $query->where(function($q2) use ($q) {
                $q2->where('ref', 'like', "%$q%")
                   ->orWhereHas('user', fn($u) => $u->where('full_name', 'like', "%$q%"))
                   ->orWhereHas('unit.workspace', fn($w) => $w->where('title', 'like', "%$q%"));
            });
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        if ($request->has('space_id')) {
            $query->whereHas('unit', fn($u) => $u->where('workspace_id', $request->space_id));
        }
        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }
        if ($request->has('date_from')) {
            $query->whereDate('start_datetime', '>=', $request->date_from);
        }
        if ($request->has('date_to')) {
            $query->whereDate('end_datetime', '<=', $request->date_to);
        }

        $sort = $request->get('sort', 'newest');
        match($sort) {
            'amount' => $query->orderBy('total_price', 'desc'),
            'date' => $query->orderBy('start_datetime', 'desc'),
            default => $query->orderBy('created_at', 'desc'),
        };

        $perPage = min($request->integer('per_page', 20), 100);
        $bookings = $query->paginate($perPage);

        return response()->json([
            'data' => $bookings->map(fn($b) => $this->formatBooking($b))->toArray(),
            'meta' => [
                'page' => $bookings->currentPage(),
                'per_page' => $bookings->perPage(),
                'total' => $bookings->total(),
                'last_page' => $bookings->lastPage(),
            ]
        ]);
    }

    /**
     * Get booking detail
     * GET /api/admin/bookings/{ref}
     */
    public function show($ref)
    {
        // Accept the stored form and the display form. Older rows may still
        // carry a leading "#", so both are tried.
        $bare = ltrim($ref, '#');
        $booking = Booking::whereIn('ref', [$bare, '#' . $bare])->firstOrFail();

        return response()->json([
            'data' => array_merge(
                $this->formatBooking($booking),
                [
                    'user_id' => $booking->user_id,
                    'space_id' => $booking->unit->workspace_id,
                    'created_at' => $booking->created_at->toIso8601String(),
                    'dispute' => $booking->dispute ? [
                        'id' => $booking->dispute->id,
                        'ref' => $booking->dispute->ref,
                        'status' => $booking->dispute->status,
                        'issue' => $booking->dispute->issue,
                        'opened' => $booking->dispute->opened_at->format('Y-m-d'),
                    ] : null,
                ]
            )
        ]);
    }

    /**
     * Update booking status
     * PATCH /api/admin/bookings/{id}/status
     */
    public function updateStatus(Request $request, $id)
    {
        $booking = Booking::findOrFail($id);

        $request->validate([
            'status' => 'required|in:completed,confirmed,disputed',
        ]);

        if ($booking->dispute && $booking->dispute->status === 'open') {
            return response()->json([
                'message' => 'Cannot update booking status while dispute is open',
                'errors' => []
            ], 409);
        }

        $booking->status = $request->status;
        $booking->save();

        return response()->json([
            'data' => [
                'id' => $booking->id,
                'ref' => $booking->ref,
                'status' => $booking->status,
            ]
        ]);
    }

    private function formatBooking($booking)
    {
        $hours = $booking->start_datetime->diffInHours($booking->end_datetime);
        $timeFormat = $booking->start_datetime->format('H:i') . ' - ' . $booking->end_datetime->format('H:i');

        return [
            'id' => $booking->id,
            'ref' => $booking->ref ?: 'BK-' . $booking->id,
            'user' => $booking->user->full_name,
            'space' => $booking->unit->workspace->title,
            'date' => $booking->start_datetime->format('Y-m-d'),
            'time' => $timeFormat,
            'hours' => $hours,
            'amount' => (int) $booking->total_price,
            'status' => $booking->status,
        ];
    }
}
