<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Review;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $spaceId = $request->query('space_id');

        $query = Review::whereHas('space', function ($q) use ($userId) {
            $q->where('user_id', $userId);
        });

        if ($spaceId) {
            $query->where('space_id', $spaceId);
        }

        return response()->json(['reviews' => $query->latest()->get()]);
    }
}