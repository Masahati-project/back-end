<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Traits\OwnerAuthorization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OwnerReviewController extends Controller
{
    use OwnerAuthorization;


    public function index(Request $request)
    {
        $this->ensureOwnerRole();

        $query = Review::with(['workspace.images'])
            ->whereHas('workspace', function ($q) {
                $q->where('owner_id', Auth::id());
            });

        if ($request->has('space_id')) {
            $spaceId = $request->query('space_id');
            $this->ensureOwnsWorkspace($spaceId);
            $query->where('workspace_id', $spaceId);
        }

        $reviews = $query->orderByDesc('created_at')
            ->get()
            ->map(fn ($review) => $this->formatReviewResponse($review));

        return response()->json(['reviews' => $reviews]);
    }

    private function formatReviewResponse(Review $review)
    {
        $spaceImage = $review->workspace->images->first()?->image_url;

        return [
            'id' => $review->id,
            'review_id' => $review->id,
            'spaceId' => $review->workspace_id,
            'space_id' => $review->workspace_id,
            'spaceName' => $review->workspace->title,
            'space_name' => $review->workspace->title,
            'spaceImage' => $spaceImage,
            'space_image' => $spaceImage,
            'image' => $spaceImage,
            'rating' => $review->rating,
            'title' => $review->title ?? '',
            'comment' => $review->comment,
            'text' => $review->comment,
            'review' => $review->comment,
            'customer' => $review->user->full_name,
            'customer_name' => $review->user->full_name,
            'customerAvatar' => $review->user->profile_picture_url,
            'customer_avatar' => $review->user->profile_picture_url,
            'avatar' => $review->user->profile_picture_url,
            'date' => $review->created_at?->toIso8601String(),
            'created_at' => $review->created_at?->toIso8601String(),
            'createdAt' => $review->created_at?->toIso8601String(),
        ];
    }
}
