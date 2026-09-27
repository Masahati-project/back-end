<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Review;
use Illuminate\Http\Request;

class AdminReviewsController extends Controller
{
    /**
     * List reviews
     * GET /api/admin/reviews
     */
    public function index(Request $request)
    {
        $query = Review::with('user', 'workspace');

        if ($request->has('q')) {
            $q = $request->q;
            $query->where(function($q2) use ($q) {
                $q2->where('comment', 'like', "%$q%")
                   ->orWhereHas('user', fn($u) => $u->where('full_name', 'like', "%$q%"))
                   ->orWhereHas('workspace', fn($w) => $w->where('title', 'like', "%$q%"));
            });
        }

        if ($request->has('filter')) {
            $filter = $request->filter;
            if ($filter === 'flagged') {
                $query->where('flagged', true)->where('visible', true);
            } elseif ($filter === 'hidden') {
                $query->where('visible', false);
            }
        }

        if ($request->has('rating')) {
            $query->where('rating', $request->rating);
        }

        $sort = $request->get('sort', 'newest');
        match($sort) {
            'lowest' => $query->orderBy('rating', 'asc'),
            'highest' => $query->orderBy('rating', 'desc'),
            default => $query->orderBy('created_at', 'desc'),
        };

        $perPage = min($request->integer('per_page', 6), 100);
        $reviews = $query->paginate($perPage);

        return response()->json([
            'data' => $reviews->map(fn($r) => $this->formatReview($r))->toArray(),
            'meta' => [
                'page' => $reviews->currentPage(),
                'per_page' => $reviews->perPage(),
                'total' => $reviews->total(),
                'last_page' => $reviews->lastPage(),
            ]
        ]);
    }

    /**
     * Get reviews stats
     * GET /api/admin/reviews/stats
     */
    public function stats()
    {
        $reviews = Review::all();
        $total = $reviews->count();
        $flagged = $reviews->where('flagged', true)->where('visible', true)->count();
        $hidden = $reviews->where('visible', false)->count();
        $average = round($reviews->where('rating', '>', 0)->avg('rating') ?? 0, 2);

        $distribution = [
            '1' => $reviews->where('rating', 1)->count(),
            '2' => $reviews->where('rating', 2)->count(),
            '3' => $reviews->where('rating', 3)->count(),
            '4' => $reviews->where('rating', 4)->count(),
            '5' => $reviews->where('rating', 5)->count(),
        ];

        return response()->json([
            'data' => [
                'total' => $total,
                'flagged' => $flagged,
                'hidden' => $hidden,
                'average_rating' => $average,
                'distribution' => $distribution,
            ]
        ]);
    }

    /**
     * Update review
     * PATCH /api/admin/reviews/{id}
     */
    public function update(Request $request, $id)
    {
        $review = Review::findOrFail($id);

        $request->validate([
            'visible' => 'sometimes|boolean',
            'flagged' => 'sometimes|boolean',
        ]);

        if ($request->has('visible')) {
            $review->visible = $request->visible;
        }
        if ($request->has('flagged')) {
            $review->flagged = $request->flagged;
        }

        $review->save();

        $message = !$review->visible ? 'تم إخفاء المراجعة.' : 'تم إظهار المراجعة.';

        return response()->json([
            'data' => [
                'id' => $review->id,
                'visible' => $review->visible,
                'flagged' => $review->flagged,
                'message' => $message,
            ]
        ]);
    }

    /**
     * Delete review
     * DELETE /api/admin/reviews/{id}
     */
    public function destroy($id)
    {
        Review::findOrFail($id)->delete();
        return response()->noContent();
    }

    private function formatReview($review)
    {
        return [
            'id' => $review->id,
            'user' => $review->user ? $review->user->full_name : 'مجهول',
            'space' => $review->workspace->title,
            'rating' => $review->rating,
            'text' => $review->comment,
            'date' => $review->created_at->format('Y-m-d'),
            'visible' => $review->visible,
            'flagged' => $review->flagged,
        ];
    }
}
