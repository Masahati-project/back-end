<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Dispute;
use App\Models\Booking;
use Illuminate\Http\Request;

class AdminDisputesController extends Controller
{
    /**
     * Resolve a dispute from the {ref} path segment.
     *
     * A "#" cannot survive the trip through a URL, so references are stored and
     * sent without one. Rows written before that was fixed still carry it, so
     * both spellings are accepted rather than 404-ing on legacy data.
     */
    private function findDispute(string $ref): Dispute
    {
        $bare = ltrim($ref, '#');
        $candidates = [$bare, '#' . $bare];

        // The panel may show the bare number, so "7" and "007" also resolve.
        if (ctype_digit($bare)) {
            $candidates[] = 'DIS-' . str_pad($bare, 3, '0', STR_PAD_LEFT);
            $candidates[] = '#DIS-' . str_pad($bare, 3, '0', STR_PAD_LEFT);
        }

        return Dispute::whereIn('ref', $candidates)->firstOrFail();
    }

    /**
     * List disputes
     * GET /api/admin/disputes
     */
    public function index(Request $request)
    {
        $query = Dispute::with('booking', 'user');

        if ($request->has('q')) {
            $q = $request->q;
            $query->where(function($q2) use ($q) {
                $q2->where('ref', 'like', "%$q%")
                   ->orWhere('issue', 'like', "%$q%")
                   ->orWhereHas('booking', fn($b) => $b->where('ref', 'like', "%$q%"))
                   ->orWhereHas('user', fn($u) => $u->where('full_name', 'like', "%$q%"));
            });
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        if ($request->has('date_from')) {
            $query->whereDate('opened_at', '>=', $request->date_from);
        }
        if ($request->has('date_to')) {
            $query->whereDate('opened_at', '<=', $request->date_to);
        }

        $perPage = min($request->integer('per_page', 20), 100);
        $disputes = $query->orderBy('opened_at', 'desc')->paginate($perPage);

        return response()->json([
            'data' => $disputes->map(fn($d) => $this->formatDisputeRow($d))->toArray(),
            'meta' => [
                'page' => $disputes->currentPage(),
                'per_page' => $disputes->perPage(),
                'total' => $disputes->total(),
                'last_page' => $disputes->lastPage(),
            ]
        ]);
    }

    /**
     * Get dispute detail
     * GET /api/admin/disputes/{ref}
     */
    public function show($ref)
    {
        $dispute = $this->findDispute($ref);

        return response()->json([
            'data' => [
                'id' => $dispute->id,
                'ref' => $dispute->ref,
                'bookingRef' => $dispute->booking->ref,
                'user' => $dispute->user->full_name,
                'space' => $dispute->booking->unit->workspace->title,
                'issue' => $dispute->issue,
                'amount' => (int) $dispute->booking->total_price,
                'status' => $dispute->status,
                'opened' => $dispute->opened_at->format('Y-m-d'),
                'booking' => [
                    'id' => $dispute->booking->id,
                    'amount' => (int) $dispute->booking->total_price,
                    'hours' => $dispute->booking->start_datetime->diffInHours($dispute->booking->end_datetime),
                    'date' => $dispute->booking->start_datetime->format('Y-m-d'),
                ],
                'history' => [
                    [
                        'at' => $dispute->opened_at->toIso8601String(),
                        'by' => 'user',
                        'action' => 'opened',
                    ]
                ]
            ]
        ]);
    }

    /**
     * Resolve dispute
     * PATCH /api/admin/disputes/{ref}/resolve
     */
    public function resolve(Request $request, $ref)
    {
        $dispute = $this->findDispute($ref);

        if ($dispute->status !== 'open') {
            return response()->json([
                'message' => 'Dispute is not in open state',
                'errors' => []
            ], 409);
        }

        $request->validate([
            'decision' => 'required|in:resolve,refund',
            'note' => 'sometimes|nullable|string|max:2000',
            'refund_amount' => 'required_if:decision,refund|numeric|min:0',
        ]);

        $dispute->admin_note = $request->note;

        if ($request->decision === 'refund') {
            $dispute->refund_amount = $request->refund_amount;
            $dispute->status = 'closed';
        } else {
            $dispute->status = 'resolved';
        }

        $dispute->resolved_at = now();
        $dispute->save();

        return response()->json([
            'data' => [
                'id' => $dispute->id,
                'ref' => $dispute->ref,
                'status' => $dispute->status,
                'refunded' => $dispute->refund_amount,
                'message' => 'تم حل النزاع.',
            ]
        ]);
    }

    private function formatDisputeRow($dispute)
    {
        return [
            'id' => $dispute->id,
            'ref' => $dispute->ref,
            'bookingRef' => $dispute->booking->ref,
            'user' => $dispute->user->full_name,
            'space' => $dispute->booking->unit->workspace->title,
            'issue' => $dispute->issue,
            'amount' => (int) $dispute->booking->total_price,
            'status' => $dispute->status,
            'opened' => $dispute->opened_at->format('Y-m-d'),
        ];
    }
}
