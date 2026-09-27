<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Models\Review;
use Illuminate\Http\Request;

class AdminSpacesController extends Controller
{
    /**
     * List spaces
     * GET /api/admin/spaces
     */
    public function index(Request $request)
    {
        $query = Workspace::with('owner', 'reviews');

        if ($request->has('q')) {
            $q = $request->q;
            $query->where(function($q2) use ($q) {
                $q2->where('title', 'like', "%$q%")
                   ->orWhere('location', 'like', "%$q%")
                   ->orWhereHas('owner', function($o) use ($q) {
                       $o->where('full_name', 'like', "%$q%");
                   });
            });
        }

        if ($request->has('status')) {
            $status = $request->status;
            if ($status === 'active') {
                $query->where('is_active', true);
            } else {
                $query->where('is_active', false);
            }
        }

        if ($request->has('neighborhood')) {
            $query->where('location', 'like', '%' . $request->neighborhood . '%');
        }

        if ($request->has('owner_id')) {
            $query->where('owner_id', $request->owner_id);
        }

        $sort = $request->get('sort', 'newest');
        match($sort) {
            'rating' => $query->with('reviews')->orderByDesc('rating'),
            'price' => $query->orderBy('price'),
            default => $query->orderBy('created_at', 'desc'),
        };

        $perPage = min($request->integer('per_page', 20), 100);
        $spaces = $query->paginate($perPage);

        return response()->json([
            'data' => $spaces->map(fn($s) => $this->formatSpaceRow($s))->toArray(),
            'meta' => [
                'page' => $spaces->currentPage(),
                'per_page' => $spaces->perPage(),
                'total' => $spaces->total(),
                'last_page' => $spaces->lastPage(),
            ]
        ]);
    }

    /**
     * Get spaces stats
     * GET /api/admin/spaces/stats
     */
    public function stats(Request $request)
    {
        $query = Workspace::query();

        if ($request->has('q')) {
            $q = $request->q;
            $query->where(function($q2) use ($q) {
                $q2->where('title', 'like', "%$q%")
                   ->orWhere('location', 'like', "%$q%");
            });
        }

        $all = $query->count();
        $active = (clone $query)->where('is_active', true)->count();
        $pending = (clone $query)->where('is_active', false)->count();
        $suspended = 0;

        return response()->json([
            'data' => [
                'all' => $all,
                'pending' => $pending,
                'active' => $active,
                'suspended' => $suspended,
            ]
        ]);
    }

    /**
     * Get space detail
     * GET /api/admin/spaces/{id}
     */
    public function show($id)
    {
        $space = Workspace::with('owner', 'reviews', 'bookings')->findOrFail($id);

        return response()->json([
            'data' => [
                'id' => $space->id,
                'name' => $space->title,
                'neighborhood' => $space->location,
                'owner' => $space->owner->full_name,
                'owner_id' => $space->owner_id,
                'price' => 15, // TODO: get actual price from units
                'status' => $space->is_active ? 'active' : 'pending',
                'rating' => round($space->reviews()->avg('rating') ?? 0, 1),
                'bookings' => $space->bookings()->count(),
                'capacity' => 24, // TODO: get actual capacity
                'image' => $space->images()->first()?->image_url,
                'description' => $space->description,
                'created_at' => $space->created_at->toIso8601String(),
                'recent_bookings' => $space->bookings()->latest()->take(5)->get()->toArray(),
                'recent_reviews' => $space->reviews()->latest()->take(5)->get()->toArray(),
            ]
        ]);
    }

    /**
     * Update space
     * PATCH /api/admin/spaces/{id}
     */
    public function update(Request $request, $id)
    {
        $space = Workspace::findOrFail($id);

        $request->validate([
            'name' => 'sometimes|string|min:1|max:80',
            'neighborhood' => 'sometimes|string|min:1|max:80',
            'price' => 'sometimes|numeric|min:1|max:100000',
            'capacity' => 'sometimes|integer|min:1|max:1000',
            'image' => 'sometimes|nullable|string|max:300',
        ]);

        if ($request->has('name')) {
            $space->title = $request->name;
        }
        if ($request->has('neighborhood')) {
            $space->location = $request->neighborhood;
        }
        if ($request->has('image') && $request->image) {
            // TODO: handle image update
        }

        $space->save();

        return response()->json([
            'data' => $this->formatSpaceDetail($space)
        ]);
    }

    /**
     * Update space status
     * PATCH /api/admin/spaces/{id}/status
     */
    public function updateStatus(Request $request, $id)
    {
        $space = Workspace::findOrFail($id);

        $request->validate([
            'status' => 'required|in:active,pending,suspended',
        ]);

        $status = $request->status;
        if ($status === 'active') {
            $space->is_active = true;
        } else {
            $space->is_active = false;
        }

        $space->save();

        return response()->json([
            'data' => [
                'id' => $space->id,
                'status' => $space->is_active ? 'active' : 'pending',
                'message' => 'تم تحديث حالة المساحة.',
            ]
        ]);
    }

    /**
     * Delete space
     * DELETE /api/admin/spaces/{id}
     */
    public function destroy($id)
    {
        $space = Workspace::findOrFail($id);

        if ($space->bookings()->where('status', 'confirmed')->exists()) {
            return response()->json([
                'message' => 'Cannot delete space with upcoming bookings',
                'errors' => []
            ], 409);
        }

        $space->deleted_at = now();
        $space->save();

        return response()->noContent();
    }

    /**
     * Export spaces to CSV
     * GET /api/admin/spaces/export
     */
    public function export(Request $request)
    {
        $query = Workspace::with('owner');

        if ($request->has('q')) {
            $q = $request->q;
            $query->where(function($q2) use ($q) {
                $q2->where('title', 'like', "%$q%");
            });
        }

        $spaces = $query->get();

        $csv = "\xEF\xBB\xBF";
        $csv .= "id,name,neighborhood,owner,price,status,rating,bookings,capacity\n";

        foreach ($spaces as $space) {
            $rating = round($space->reviews()->avg('rating') ?? 0, 1);
            $csv .= implode(',', [
                $space->id,
                $space->title,
                $space->location,
                $space->owner->full_name,
                15, // TODO: get price
                $space->is_active ? 'active' : 'pending',
                $rating,
                $space->bookings()->count(),
                24, // TODO: get capacity
            ]) . "\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="spaces-' . now()->format('Y-m-d') . '.csv"',
        ]);
    }

    private function formatSpaceRow($space)
    {
        $rating = round($space->reviews()->avg('rating') ?? 0, 1);

        return [
            'id' => $space->id,
            'name' => $space->title,
            'neighborhood' => $space->location,
            'owner' => $space->owner->full_name,
            'price' => 15,
            'status' => $space->is_active ? 'active' : 'pending',
            'rating' => $rating,
            'bookings' => $space->bookings()->count(),
            'capacity' => 24,
            'image' => $space->images()->first()?->image_url,
        ];
    }

    private function formatSpaceDetail($space)
    {
        return [
            'id' => $space->id,
            'name' => $space->title,
            'neighborhood' => $space->location,
            'owner' => $space->owner->full_name,
            'owner_id' => $space->owner_id,
            'price' => 15,
            'status' => $space->is_active ? 'active' : 'pending',
            'rating' => round($space->reviews()->avg('rating') ?? 0, 1),
            'bookings' => $space->bookings()->count(),
            'capacity' => 24,
            'image' => $space->images()->first()?->image_url,
            'description' => $space->description,
            'created_at' => $space->created_at->toIso8601String(),
        ];
    }
}
