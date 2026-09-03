<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use Illuminate\Http\Request;

class SpacesController extends Controller
{
    public function index(Request $request)
    {
        $spaces = Workspace::where('status', 'approved')
            ->with('images')
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        $data = $spaces->getCollection()->map(function ($space) {
            return [
                'space_id' => $space->id,
                'title' => $space->name,
                'description' => $space->description,
                'location' => trim($space->address . '، ' . $space->city, '، '),
                'image' => $space->images->first()?->image_url,
            ];
        });

        return response()->json([
            'data' => $data,
            'current_page' => $spaces->currentPage(),
            'last_page' => $spaces->lastPage(),
            'has_more' => $spaces->hasMorePages(),
        ]);
    }
}
