<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Amenity;
use App\Models\Workspace;
use App\Services\ImageService;
use App\Traits\OwnerAuthorization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OwnerSpaceController extends Controller
{
    use OwnerAuthorization;


    public function index()
    {
        $this->ensureOwnerRole();

        $spaces = Workspace::where('owner_id', Auth::id())->get()->map(function ($space) {
            return $this->formatSpaceResponse($space);
        });

        return response()->json(['spaces' => $spaces]);
    }

    public function store(Request $request)
    {
        $this->ensureOwnerRole();

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'location' => 'required|string',
            'lat' => 'required|numeric',
            'lng' => 'required|numeric',
            'price_per_hour' => 'required|numeric',
            'capacity' => 'required|integer',
            'amenities' => 'required|array',
            'internet' => 'required|boolean',
            'power' => 'required|boolean',
            'image' => 'nullable|string',
            'docs' => 'nullable|array',
            'space_document_url' => 'nullable|string|max:255',
            'open_time' => 'required',
            'close_time' => 'required',
            'contact_phone' => 'required'
        ]);

        $space = Workspace::create([
            'owner_id' => Auth::id(),
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'location' => $validated['location'],
            'latitude' => $validated['lat'],
            'longitude' => $validated['lng'],
            'status' => 'pending',
            'open_time' => $validated['open_time'],
            'close_time' => $validated['close_time'],
            'contact_phone' => $validated['contact_phone'],
            // Optional: an owner may create the space before the document
            // exists, hence a nullable column instead of a NOT NULL one.
            'space_document_url' => $validated['space_document_url'] ?? null,
            'is_closed' => false,
            'is_active' => false,
        ]);

        // Store image in workspace_images table
        $imagePath = null;
        if (!empty($validated['image'])) {
            $imagePath = ImageService::storeBase64Image($validated['image'], 'spaces');
            \App\Models\WorkspaceImage::create([
                'workspace_id' => $space->id,
                'image_url' => $imagePath,
            ]);
        }

        // Create default unit with pricing
        $unit = \App\Models\Unit::create([
            'workspace_id' => $space->id,
            'type' => 'full_space',
            'capacity' => $validated['capacity'],
            'has_wifi' => $validated['internet'],
            'has_power' => $validated['power'],
            'status' => 'available',
        ]);

        // Create hourly pricing
        \App\Models\Pricing::create([
            'unit_id' => $unit->id,
            'price_type' => 'hourly',
            'price' => $validated['price_per_hour'],
            'currency' => 'ILS',
        ]);

        // Handle amenities
        if (!empty($validated['amenities'])) {
            $amenityIds = [];
            foreach ($validated['amenities'] as $amenity) {
                $amenityName = is_array($amenity) ? ($amenity['name'] ?? $amenity['key'] ?? null) : $amenity;
                if ($amenityName) {
                    $amenityIds[] = $this->resolveAmenityId($amenityName);
                }
            }
            $space->amenities()->sync($amenityIds);
        }

        return response()->json([
            'message' => 'تمت إضافة المساحة.',
            'space' => $this->formatSpaceResponse($space),
        ], 201);
    }

    public function update(Request $request, string $id)
    {
        $this->ensureOwnerRole();
        $space = $this->ensureOwnsWorkspace($id);

        $validated = $request->validate([
            'title' => 'string|max:255',
            'description' => 'string',
            'location' => 'string',
            'lat' => 'nullable|numeric',
            'lng' => 'nullable|numeric',
            'price_per_hour' => 'numeric',
            'capacity' => 'integer',
            'amenities' => 'array',
            'internet' => 'boolean',
            'power' => 'boolean',
            'image' => 'nullable|string',
        ]);

        $updateData = [
            'title' => $validated['title'] ?? $space->title,
            'description' => $validated['description'] ?? $space->description,
            'location' => $validated['location'] ?? $space->location,
            'latitude' => $validated['lat'] ?? $space->latitude,
            'longitude' => $validated['lng'] ?? $space->longitude,
        ];

        $space->update($updateData);

        // Update image in workspace_images table
        if (isset($validated['image']) && !empty($validated['image'])) {
            $imagePath = ImageService::storeBase64Image($validated['image'], 'spaces');
            // Delete old images
            $space->images()->delete();
            // Create new image
            \App\Models\WorkspaceImage::create([
                'workspace_id' => $space->id,
                'image_url' => $imagePath,
            ]);
        }

        // Update pricing if provided
        if (isset($validated['price_per_hour'])) {
            $unit = $space->units()->first();
            if ($unit) {
                $pricing = \App\Models\Pricing::where('unit_id', $unit->id)
                    ->where('price_type', 'hourly')
                    ->first();

                if ($pricing) {
                    $pricing->update(['price' => $validated['price_per_hour']]);
                } else {
                    \App\Models\Pricing::create([
                        'unit_id' => $unit->id,
                        'price_type' => 'hourly',
                        'price' => $validated['price_per_hour'],
                        'currency' => 'ILS',
                    ]);
                }
            }
        }

        // Update unit capacity and features if provided
        $unit = $space->units()->first();
        if ($unit) {
            $unitUpdateData = [];
            if (isset($validated['capacity'])) {
                $unitUpdateData['capacity'] = $validated['capacity'];
            }
            if (isset($validated['internet'])) {
                $unitUpdateData['has_wifi'] = $validated['internet'];
            }
            if (isset($validated['power'])) {
                $unitUpdateData['has_power'] = $validated['power'];
            }

            if (!empty($unitUpdateData)) {
                $unit->update($unitUpdateData);
            }
        }

        // Handle amenities
        if (isset($validated['amenities'])) {
            $amenityIds = [];
            foreach ($validated['amenities'] as $amenity) {
                $amenityName = is_array($amenity) ? ($amenity['name'] ?? $amenity['key'] ?? null) : $amenity;
                if ($amenityName) {
                    $amenityIds[] = $this->resolveAmenityId($amenityName);
                }
            }
            $space->amenities()->sync($amenityIds);
        }

        return response()->json([
            'message' => 'تم تحديث المساحة.',
            'space' => $this->formatSpaceResponse($space),
        ]);
    }

    public function toggleActive(Request $request, string $id)
    {
        $this->ensureOwnerRole();
        $space = $this->ensureOwnsWorkspace($id);

        $validated = $request->validate([
            'is_active' => 'required|boolean',
        ]);

        $space->update(['is_active' => $validated['is_active']]);

        return response()->json([
            'message' => $validated['is_active'] ? 'تم تفعيل المساحة.' : 'تم تعطيل المساحة.',
            'space' => $this->formatSpaceResponse($space),
        ]);
    }

    public function destroy(string $id)
    {
        $this->ensureOwnerRole();
        $space = $this->ensureOwnsWorkspace($id);

        // Check if there are pending/confirmed bookings. bookings() is a
        // HasManyThrough joining units, so status must be qualified.
        $activeBookings = $space->bookings()
            ->whereIn('bookings.status', ['pending', 'confirmed', 'accepted'])
            ->count();

        if ($activeBookings > 0) {
            return response()->json([
                'message' => 'لا يمكن حذف مساحة بها حجوزات نشطة.',
            ], 409);
        }

        $space->delete();

        return response()->json(['message' => 'تم حذف المساحة.']);
    }

    /**
     * Resolve an amenity by name, creating it when missing.
     *
     * icon is NOT NULL with no default, so it has to be supplied on create.
     * Nothing in the app reads the column — responses carry amenity names only
     * — so a neutral placeholder is enough.
     */
    private function resolveAmenityId(string $name): int
    {
        return (int) Amenity::firstOrCreate(['name' => $name], ['icon' => 'check'])->id;
    }

    private function formatSpaceResponse(Workspace $space)
    {
        $stats = $space->getStats();
        $amenities = $space->amenities->pluck('name')->toArray();

        // Get hourly price from first unit's pricing
        $pricePerHour = 0;
        $unit = $space->units()->first();
        if ($unit) {
            $pricing = $unit->pricing()
                ->where('price_type', 'hourly')
                ->first();
            if ($pricing) {
                $pricePerHour = $pricing->price;
            }
        }

        // Get capacity from first unit
        $capacity = $unit ? $unit->capacity : 0;
        $internet = $unit ? $unit->has_wifi : false;
        $power = $unit ? $unit->has_power : false;

        // Get image from workspace_images table
        $image = $space->images->first()?->image_url;

        return [
            'id' => $space->id,
            'space_id' => $space->id,
            'title' => $space->title,
            'description' => $space->description,
            'location' => $space->location,
            'lat' => $space->latitude,
            'lng' => $space->longitude,
            'latitude' => $space->latitude,
            'longitude' => $space->longitude,
            'image' => $image,
            'gallery' => $space->images->pluck('image_url')->toArray(),
            'price_per_hour' => $pricePerHour,
            'price' => $pricePerHour,
            'capacity' => $capacity,
            'amenities' => $amenities,
            'internet' => $internet,
            'has_internet' => $internet,
            'wifi' => $internet,
            'power' => $power,
            'has_power' => $power,
            'electricity' => $power,
            'status' => $space->status,
            'is_active' => $space->is_active ?? false,
            'rating' => round($space->reviews()->avg('rating') ?? 0, 1),
            'stats' => $stats,
        ];
    }
}
