<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Models\Review;
use Illuminate\Http\Request;

class AdminSpacesController extends Controller
{
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
        $active = $query->where('is_active', true)->count();
        $pending = $query->where('status', 'pending')->count();
        $suspended = $query->where('status', 'rejected')->count();

        return response()->json([
            'data' => [
                'all' => $all,
                'pending' => $pending,
                'active' => $active,
                'suspended' => $suspended,
            ]
        ]);
    }

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
                'price' => 15,
                'status' => $space->is_active ? 'active' : 'pending',
                'rating' => round($space->reviews()->avg('rating') ?? 0, 1),
                'bookings' => $space->bookings()->count(),
                'capacity' => 24,
                'image' => $space->images()->first()?->image_url,
                'description' => $space->description,
                'created_at' => $space->created_at->toIso8601String(),
                'recent_bookings' => $space->bookings()->latest()->take(5)->get()->toArray(),
                'recent_reviews' => $space->reviews()->latest()->take(5)->get()->toArray(),
            ]
        ]);
    }

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

    public function destroy($id)
    {
        $space = Workspace::findOrFail($id);

        if ($space->bookings()->where('bookings.status', 'confirmed')->exists()) {
            return response()->json([
                'message' => 'Cannot delete space with upcoming bookings',
                'errors' => []
            ], 409);
        }

        $space->delete();

        return response()->noContent();
    }

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
                $this->spacePrice($space),
                $space->is_active ? 'active' : 'pending',
                $rating,
                $space->bookings()->count(),
                $this->spaceCapacity($space),
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
        $price = $this->spacePrice($space);
        $capacity = $this->spaceCapacity($space);

        return [
            'id' => $space->id,
            'name' => $space->title,
            'neighborhood' => $space->location,
            'owner' => $space->owner->full_name,
            'price' => $price,
            'status' => $space->is_active ? 'active' : 'pending',
            'rating' => $rating,
            'bookings' => $space->bookings()->count(),
            'capacity' => $capacity,
            'image' => $space->images()->first()?->image_url,
        ];
    }

    private function formatSpaceDetail($space)
    {
        $price = $this->spacePrice($space);
        $capacity = $this->spaceCapacity($space);

        return [
            'id' => $space->id,
            'name' => $space->title,
            'neighborhood' => $space->location,
            'owner' => $space->owner->full_name,
            'owner_id' => $space->owner_id,
            'price' => $price,
            'status' => $space->is_active ? 'active' : 'pending',
            'rating' => round($space->reviews()->avg('rating') ?? 0, 1),
            'bookings' => $space->bookings()->count(),
            'capacity' => $capacity,
            'image' => $space->images()->first()?->image_url,
            'description' => $space->description,
            'created_at' => $space->created_at->toIso8601String(),
        ];
    }

    private function spacePrice(Workspace $space): mixed
    {
        $unit = $space->units()->first();
        if (!$unit) {
            return 15;
        }
        $pricing = $unit->pricing()->orderBy('created_at', 'desc')->first();
        if (!$pricing) {
            return 15;
        }
        return $pricing->price;
    }

    private function spaceCapacity(Workspace $space): mixed
    {
        $unit = $space->units()->first();
        if (!$unit) {
            return 24;
        }
        return $unit->capacity;
    }
}