<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Amenity;
use App\Models\Booking;
use App\Models\Unit;
use App\Models\Workspace;
use App\Services\ImageService;
use App\Traits\OwnerAuthorization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OwnerSpaceController extends Controller
{
    use OwnerAuthorization;

    private const OPEN_SPACES_LIMIT = 100;
    private const STATS_SPACES_LIMIT = 50;

    public function index()
    {
        $this->ensureOwnerRole();

        $spaces = Workspace::where('owner_id', Auth::id())->get()->map(function ($space) {
            return $this->formatSpaceResponse($space);
        });

        return response()->json(['spaces' => $spaces]);
    }

    public function open()
    {
        $this->ensureOwnerRole();

        $spaces = $this->ownedSpaces()
            ->where('status', 'active')
            ->where('is_active', true)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::OPEN_SPACES_LIMIT)
            ->get()
            ->map(fn ($space) => $this->formatSpaceResponse($space));

        return response()->json([
            'spaces' => $spaces,
            'open_spaces' => $spaces,
            'count' => $spaces->count(),
            'message' => 'تم جلب المساحات المفتوحة بنجاح.',
        ]);
    }

    public function stats()
    {
        $this->ensureOwnerRole();

        $query = $this->ownedSpaces();

        $total = $query->count();

        $byStatus = [
            'pending' => (clone $query)->where('status', 'pending')->count(),
            'active' => (clone $query)->where('status', 'active')->count(),
            'suspended' => (clone $query)->where('status', 'suspended')->count(),
            'rejected' => (clone $query)->where('status', 'rejected')->count(),
        ];

        $active = (clone $query)->where('is_active', true)->count();
        $inactive = (clone $query)->where('is_active', false)->count();

        $live = (clone $query)->where('status', 'active')->where('is_active', true)->count();

        $spaces = (clone $query)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::STATS_SPACES_LIMIT)
            ->get();

        $totals = $this->monthlyBookingTotals($spaces->pluck('id')->all());

        $monthlyRevenue = 0.0;
        $monthlyBookings = 0;

        $rows = $spaces->map(function (Workspace $space) use ($totals, &$monthlyRevenue, &$monthlyBookings) {
            $row = $totals[$space->id] ?? ['bookings' => 0, 'revenue' => 0.0, 'units' => 0, 'all_time' => 0];

            $monthlyBookings += $row['bookings'];
            $monthlyRevenue += $row['revenue'];

            $occupancy = $row['units'] > 0
                ? min((int) round(($row['bookings'] / ($row['units'] * 30)) * 100), 100)
                : 0;

            return [
                'id' => $space->id,
                'space_id' => $space->id,
                'title' => $space->title,
                'status' => $space->status,
                'is_active' => (bool) $space->is_active,
                'created_at' => $space->created_at?->toIso8601String(),
                'stats' => [
                    'bookings' => $row['bookings'],
                    'revenue' => round((float) $row['revenue'], 2),
                    'occupancy' => $occupancy,
                    'totalBookings' => $row['all_time'],
                    'total_bookings' => $row['all_time'],
                ],
            ];
        });

        $payload = [
            'total' => $total,
            'total_spaces' => $total,
            'pending' => $byStatus['pending'],
            'active' => $byStatus['active'],
            'suspended' => $byStatus['suspended'],
            'rejected' => $byStatus['rejected'],
            // Retained so an owner dashboard build still reading the old spelling
            // keeps working; both now count the same rows.
            'approved' => $byStatus['active'],
            'listed' => $active,
            'inactive' => $inactive,
            'by_status' => $byStatus,
            'by_is_active' => ['active' => $active, 'inactive' => $inactive],
            'live' => $live,
            'open' => $live,
            'revenue' => round($monthlyRevenue, 2),
            'monthly_revenue' => round($monthlyRevenue, 2),
            'monthly_bookings' => $monthlyBookings,
            'month' => now()->format('Y-m'),
            'spaces' => $rows,
            'spaces_count' => $rows->count(),
            'truncated' => $total > $rows->count(),
        ];

        return response()->json([
            'message' => 'تم جلب إحصائيات المساحات بنجاح.',
            'stats' => $payload,
            'data' => $payload,
        ]);
    }

    public function store(Request $request)
    {
        $this->ensureOwnerRole();

        $this->normalizeListingPayload($request);

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
            // The proof file behind the listing — the deed or the lease — is part
            // of creating the listing, not a later step. There is still
            // POST /owner/spaces/{space}/documents for replacing it once the
            // space exists, but a space cannot be created without one.
            'docs' => 'required|file|mimes:pdf,png,jpg,jpeg,webp|max:10240',
            'open_time' => 'required',
            'close_time' => 'required',
            'contact_phone' => 'required'
        ]);

        // Storage being unreachable is a server fault, and this file is required:
        // answering 201 with a null space_document_url would tell the owner their
        // deed is on file when it is not.
        $proofPath = $request->file('docs')->store('documents', 'cloudinary');

        if (! $proofPath) {
            throw new \RuntimeException('تعذّر حفظ المستند على الخادم.');
        }

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
            'space_document_url' => $proofPath,
            'is_closed' => false,
            'is_active' => false,
        ]);

        $imagePath = null;
        if (!empty($validated['image'])) {
            $imagePath = ImageService::storeBase64Image($validated['image'], 'spaces');
            \App\Models\WorkspaceImage::create([
                'workspace_id' => $space->id,
                'image_url' => $imagePath,
            ]);
        }

        $unit = \App\Models\Unit::create([
            'workspace_id' => $space->id,
            'type' => 'full_space',
            'capacity' => $validated['capacity'],
            'has_wifi' => $validated['internet'],
            'has_power' => $validated['power'],
            'status' => 'available',
        ]);

        \App\Models\Pricing::create([
            'unit_id' => $unit->id,
            'price_type' => 'hourly',
            'price' => $validated['price_per_hour'],
            'currency' => 'ILS',
        ]);

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

    public function update(Request $request, int $id)
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

        if (isset($validated['image']) && !empty($validated['image'])) {
            $imagePath = ImageService::storeBase64Image($validated['image'], 'spaces');
            $space->images()->delete();
            \App\Models\WorkspaceImage::create([
                'workspace_id' => $space->id,
                'image_url' => $imagePath,
            ]);
        }

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

    public function toggleActive(Request $request, int $id)
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

    public function destroy(int $id)
    {
        $this->ensureOwnerRole();
        $space = $this->ensureOwnsWorkspace($id);

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

    private function ownedSpaces(): Builder
    {
        return Workspace::where('owner_id', Auth::id())->whereNull('deleted_at');
    }

    /**
     * Every create call carries `docs`, so every create call is multipart — and
     * in multipart every other field arrives as a string. `boolean` accepts only
     * true/false/1/0 and `array` accepts only a real array, so a form posting
     * `internet=true` and `amenities=["wifi"]` was rejected with a 422 that read
     * as "this form is broken" instead of "these two fields need a different
     * encoding". Anything still unrecognisable is left untouched so validation
     * reports it.
     */
    private function normalizeListingPayload(Request $request): void
    {
        $merge = [];

        foreach (['internet', 'power'] as $flag) {
            if (! is_string($request->input($flag))) {
                continue;
            }

            $boolean = filter_var($request->input($flag), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($boolean !== null) {
                $merge[$flag] = $boolean;
            }
        }

        // FormData cannot nest, so a client with a list of amenities sends it
        // JSON-encoded. Note that appending the same key once per amenity does
        // not survive: PHP keeps only the last value.
        if (is_string($request->input('amenities'))) {
            $decoded = json_decode($request->input('amenities'), true);

            if (is_array($decoded)) {
                $merge['amenities'] = $decoded;
            }
        }

        if ($merge !== []) {
            $request->merge($merge);
        }
    }

    private function monthlyBookingTotals(array $workspaceIds): array
    {
        $empty = array_fill_keys($workspaceIds, [
            'bookings' => 0,
            'revenue' => 0.0,
            'all_time' => 0,
            'units' => 0,
        ]);

        if ($workspaceIds === []) {
            return $empty;
        }

        $unitToWorkspace = Unit::whereIn('workspace_id', $workspaceIds)
            ->pluck('workspace_id', 'id')
            ->all();

        if ($unitToWorkspace === []) {
            return $empty;
        }

        $thisMonth = $this->confirmedBookingsPerUnit(array_keys($unitToWorkspace), true);
        $allTime = $this->confirmedBookingsPerUnit(array_keys($unitToWorkspace), false);

        foreach ($unitToWorkspace as $unitId => $workspaceId) {
            $empty[$workspaceId]['units']++;

            if (isset($thisMonth[$unitId])) {
                $empty[$workspaceId]['bookings'] += $thisMonth[$unitId]['bookings'];
                $empty[$workspaceId]['revenue'] += $thisMonth[$unitId]['revenue'];
            }

            if (isset($allTime[$unitId])) {
                $empty[$workspaceId]['all_time'] += $allTime[$unitId]['bookings'];
            }
        }

        return $empty;
    }

    private function confirmedBookingsPerUnit(array $unitIds, bool $currentMonthOnly): array
    {
        if ($unitIds === []) {
            return [];
        }

        $query = Booking::whereIn('unit_id', $unitIds)
            ->where('bookings.status', 'confirmed');

        if ($currentMonthOnly) {
            $query->whereMonth('bookings.created_at', now()->month);
        }

        return $query->selectRaw('unit_id, count(*) as booking_count, sum(total_price) as revenue')
            ->groupBy('unit_id')
            ->get()
            ->mapWithKeys(fn ($row) => [
                (int) $row->unit_id => [
                    'bookings' => (int) $row->booking_count,
                    'revenue' => (float) $row->revenue,
                ],
            ])
            ->all();
    }

    private function resolveAmenityId(string $name): int
    {
        return (int) Amenity::firstOrCreate(['name' => $name], ['icon' => 'check'])->id;
    }

    private function formatSpaceResponse(Workspace $space)
    {
        $stats = $space->getStats();
        $amenities = $space->amenities->pluck('name')->toArray();

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

        $capacity = $unit ? $unit->capacity : 0;
        $internet = $unit ? $unit->has_wifi : false;
        $power = $unit ? $unit->has_power : false;

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
            // The proof file behind the listing, so the owner's own screen can
            // show that the upload landed instead of assuming it did.
            'space_document_url' => $space->space_document_url,
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
