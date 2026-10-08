<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Amenity;
<<<<<<< HEAD
use App\Models\Booking;
use App\Models\Unit;
use App\Models\Workspace;
use App\Services\ImageService;
use App\Traits\OwnerAuthorization;
use Illuminate\Database\Eloquent\Builder;
=======
use App\Models\Workspace;
use App\Services\ImageService;
use App\Traits\OwnerAuthorization;
>>>>>>> 70ab341a93cda185b5426b47c12600dcb3d90687
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OwnerSpaceController extends Controller
{
    use OwnerAuthorization;

<<<<<<< HEAD
    /**
     * Hard cap on GET /api/owner/spaces/open.
     *
     * The list is what a dashboard card renders, not a browsable catalogue, and
     * index() returns an unbounded get() because it predates any concern about a
     * large table. A new endpoint should not inherit that: the query below is
     * bounded so one request can never serialise an owner's entire inventory —
     * and every item in it runs a projection that loads images, amenities, units,
     * pricing and bookings, so an unbounded list is expensive per row.
     */
    private const OPEN_SPACES_LIMIT = 100;

    /**
     * Hard cap on the per-space rows carried by GET /api/owner/spaces/stats.
     *
     * The counts above the fold are computed over ALL of the owner's spaces and are
     * never truncated; only the itemised list is, so a dashboard that owns more than
     * this many spaces still reports honest totals.
     */
    private const STATS_SPACES_LIMIT = 50;
=======
>>>>>>> 70ab341a93cda185b5426b47c12600dcb3d90687

    public function index()
    {
        $this->ensureOwnerRole();

        $spaces = Workspace::where('owner_id', Auth::id())->get()->map(function ($space) {
            return $this->formatSpaceResponse($space);
        });

        return response()->json(['spaces' => $spaces]);
    }

<<<<<<< HEAD
    /**
     * GET /api/owner/spaces/open — the owner's live listings.
     *
     * "Live" means two independent conditions, and both are enforced here rather
     * than left to the page: the space has been approved by an admin
     * (status = 'approved'), and the owner has not switched it off from the
     * dashboard (is_active = true). A space missing either one is invisible to
     * customers, so listing it here would only be a source of false confidence.
     *
     * Ownership needs no second check: the query is already scoped to
     * owner_id = Auth::id(), so another owner's rows can never enter the result —
     * that is the whole of the isolation story for this endpoint.
     */
    public function open()
    {
        $this->ensureOwnerRole();

        $spaces = $this->ownedSpaces()
            ->where('status', 'approved')
            ->where('is_active', true)
            // Newest first, with the id as the tie-breaker so two spaces created in
            // the same second cannot swap places between two requests.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::OPEN_SPACES_LIMIT)
            ->get()
            // formatSpaceResponse() is reused rather than re-projected so this list
            // is byte-identical in shape to index(): the dashboard card and the full
            // list cannot drift apart as fields are added.
            ->map(fn ($space) => $this->formatSpaceResponse($space));

        return response()->json([
            'spaces' => $spaces,
            // House rule: an ambiguous key is published under both spellings the
            // frontend may read, so a card built against either one renders the
            // list instead of an empty section.
            'open_spaces' => $spaces,
            // The number of rows in THIS response, which the cap can make smaller
            // than the owner's total; it is not a total and must not be used as one.
            'count' => $spaces->count(),
            'message' => 'تم جلب المساحات المفتوحة بنجاح.',
        ]);
    }

    /**
     * GET /api/owner/spaces/stats — the owner's dashboard counters.
     *
     * WHAT IS INCLUDED, and why:
     *  - totals plus a breakdown by moderation status and by the on/off switch,
     *    which is what an owner needs in order to answer "why is nothing showing?";
     *  - `live`, the approved-and-active count, which is by definition the length of
     *    GET /api/owner/spaces/open — it lets the client label that card without
     *    counting the rows itself;
     *  - the per-space figures Workspace::getStats() computes (confirmed bookings and
     *    their revenue for the current month, plus occupancy), computed in bulk by
     *    monthlyBookingTotals() rather than by calling getStats() in a loop.
     *
     * Every count is scoped through ownedSpaces(), so the numbers describe this owner
     * only and cannot be used to infer anybody else's inventory.
     */
    public function stats()
    {
        $this->ensureOwnerRole();

        $query = $this->ownedSpaces();

        $total = $query->count();

        $byStatus = [
            'pending' => (clone $query)->where('status', 'pending')->count(),
            'approved' => (clone $query)->where('status', 'approved')->count(),
            'rejected' => (clone $query)->where('status', 'rejected')->count(),
        ];

        $active = (clone $query)->where('is_active', true)->count();
        $inactive = (clone $query)->where('is_active', false)->count();

        // approved AND active: exactly what open() lists. Kept in SQL rather than
        // derived from the two counters above, because a space with an unexpected
        // status would make that subtraction quietly wrong.
        $live = (clone $query)->where('status', 'approved')->where('is_active', true)->count();

        // The itemised part. Only this is capped; every counter above covers all of
        // the owner's spaces.
        $spaces = (clone $query)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::STATS_SPACES_LIMIT)
            ->get();

        $totals = $this->monthlyBookingTotals($spaces->pluck('id')->all());

        $monthlyRevenue = 0.0;
        $monthlyBookings = 0;

        $rows = $spaces->map(function (Workspace $space) use ($totals, &$monthlyRevenue, &$monthlyBookings) {
            // monthBookings / monthRevenue / units / allTimeBookings, all defaulted by
            // monthlyBookingTotals() for a space that has no units at all yet.
            $row = $totals[$space->id] ?? ['bookings' => 0, 'revenue' => 0.0, 'units' => 0, 'all_time' => 0];

            $monthlyBookings += $row['bookings'];
            $monthlyRevenue += $row['revenue'];

            // The same formula Workspace::getStats() uses: confirmed bookings this
            // month against one month's worth of capacity (30 days per unit), capped
            // at 100. Mirrored rather than improved, so the number on this card and
            // the number inside each space's own `stats` block can never disagree.
            $occupancy = $row['units'] > 0
                ? min((int) round(($row['bookings'] / ($row['units'] * 30)) * 100), 100)
                : 0;

            return [
                // Both spellings of the id, per the house rule.
                'id' => $space->id,
                'space_id' => $space->id,
                'title' => $space->title,
                'status' => $space->status,
                'is_active' => (bool) $space->is_active,
                'created_at' => $space->created_at?->toIso8601String(),
                'stats' => [
                    // bookings / revenue / occupancy are scoped to the current month,
                    // the same scope getStats() uses.
                    'bookings' => $row['bookings'],
                    // total_price is decimal(10,2) and MySQL returns SUM() of a
                    // decimal as a STRING ("150.00"). Undo the cast at the boundary so
                    // the frontend renders a number rather than string-concatenating.
                    'revenue' => round((float) $row['revenue'], 2),
                    'occupancy' => $occupancy,
                    // All-time confirmed bookings. Both spellings, because the field
                    // name inherited from getStats() is camelCase while the rest of
                    // this API is snake_case.
                    'totalBookings' => $row['all_time'],
                    'total_bookings' => $row['all_time'],
                ],
            ];
        });

        $payload = [
            'total' => $total,
            'total_spaces' => $total,
            // Flat, so a stat card can read stats.approved directly ...
            'pending' => $byStatus['pending'],
            'approved' => $byStatus['approved'],
            'rejected' => $byStatus['rejected'],
            'active' => $active,
            'inactive' => $inactive,
            // ... and nested, for a card that renders the breakdown as a list.
            'by_status' => $byStatus,
            'by_is_active' => ['active' => $active, 'inactive' => $inactive],
            // The length of GET /api/owner/spaces/open.
            'live' => $live,
            'open' => $live,
            // Revenue is ambiguous without its window, so it is published under both
            // names: `revenue` for the client that reads the short key and
            // `monthly_revenue` for the one that needs the window spelled out.
            'revenue' => round($monthlyRevenue, 2),
            'monthly_revenue' => round($monthlyRevenue, 2),
            'monthly_bookings' => $monthlyBookings,
            // Stamped so a cached card can tell which month the figures describe:
            // getStats() filters on the month only, without the year.
            'month' => now()->format('Y-m'),
            // Only STATS_SPACES_LIMIT spaces are itemised; `spaces_count` and
            // `truncated` say so explicitly rather than letting a client infer it.
            'spaces' => $rows,
            'spaces_count' => $rows->count(),
            'truncated' => $total > $rows->count(),
        ];

        return response()->json([
            'message' => 'تم جلب إحصائيات المساحات بنجاح.',
            'stats' => $payload,
            // `data` is the envelope key this API documents for list endpoints
            // (SpecialRequestController, PublicAdController and every admin endpoint
            // return it), mirrored so a client written against either key renders the
            // counters instead of an empty panel.
            'data' => $payload,
        ]);
    }

=======
>>>>>>> 70ab341a93cda185b5426b47c12600dcb3d90687
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

<<<<<<< HEAD
    /**
     * `int $id` rather than `string $id`: the route declares
     * ->whereNumber('space'), so the segment is digits only, and a PHP type of int
     * makes the same guarantee at the method boundary. Laravel hands route
     * parameters over as raw strings, but these files are not in strict_types mode,
     * so "12" coerces to 12; anything non-numeric never gets this far because the
     * route constraint rejects it first.
     */
    public function update(Request $request, int $id)
=======
    public function update(Request $request, string $id)
>>>>>>> 70ab341a93cda185b5426b47c12600dcb3d90687
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

<<<<<<< HEAD
    /**
     * `int $id` for the same reason as update(): ->whereNumber('space') on the route.
     */
    public function toggleActive(Request $request, int $id)
=======
    public function toggleActive(Request $request, string $id)
>>>>>>> 70ab341a93cda185b5426b47c12600dcb3d90687
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

<<<<<<< HEAD
    /**
     * `int $id` for the same reason as update(): ->whereNumber('space') on the route.
     */
    public function destroy(int $id)
=======
    public function destroy(string $id)
>>>>>>> 70ab341a93cda185b5426b47c12600dcb3d90687
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
<<<<<<< HEAD
     * Every query in this controller starts here.
     *
     * owner_id = Auth::id() is what makes these endpoints owner's-only: scoping to
     * the caller is stronger than filtering a full result set afterwards, because a
     * row that is never selected cannot leak through a projection.
     *
     * The deleted_at test is deliberate. Workspace imports SoftDeletes but never
     * applies the trait, so the query builder does NOT exclude a row whose
     * deleted_at was set — that column only exists because the migration added it.
     * index() still returns such a row (it predates the concern, and its own shape is
     * a contract), but a new endpoint that reports "your live listings" must not
     * count or list one: a space the owner deleted is not live.
     */
    private function ownedSpaces(): Builder
    {
        return Workspace::where('owner_id', Auth::id())->whereNull('deleted_at');
    }

    /**
     * The figures Workspace::getStats() computes, for many spaces in one go.
     *
     * getStats() runs five queries per space (three over the bookings HasManyThrough
     * and two unit counts), so calling it in a loop over the dashboard page is an N+1
     * an owner triggers simply by owning more spaces. This answers the same three
     * questions with a fixed three queries no matter how many ids are passed in:
     *
     *   bookings  confirmed bookings created in the current month
     *   revenue   their total_price
     *   all_time  confirmed bookings ever, which is getStats()'s totalBookings
     *   units     how many units the space has, the denominator of the occupancy %
     *
     * The month filter is `whereMonth(created_at, now()->month)` with no year, copied
     * from getStats() on purpose: "improving" it here would make this card disagree
     * with the per-space `stats` block the very same API serves.
     *
     * SQL-portable by construction: only GROUP BY on a single column, no JSON
     * functions, no driver-specific date syntax, so the SQLite test run and the
     * MySQL production run execute the same statement.
     */
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

        // unit id => workspace id. The join Workspace::bookings() would make
        // (bookings -> units -> workspaces) in one hop, which keeps the query count
        // at three instead of four.
        $unitToWorkspace = Unit::whereIn('workspace_id', $workspaceIds)
            ->pluck('workspace_id', 'id')
            ->all();

        if ($unitToWorkspace === []) {
            return $empty;
        }

        $thisMonth = $this->confirmedBookingsPerUnit(array_keys($unitToWorkspace), true);
        $allTime = $this->confirmedBookingsPerUnit(array_keys($unitToWorkspace), false);

        foreach ($unitToWorkspace as $unitId => $workspaceId) {
            // A unit of one of the listed spaces, so the key always exists: fill_keys
            // above seeded it from the same id list.
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

    /**
     * Bookings per unit, grouped in SQL so the caller never loads the rows.
     *
     * `bookings.status` is qualified by name because the query only touches the
     * bookings table, and total_price is decimal(10,2): MySQL returns the SUM of a
     * decimal as a string, so both aggregates are cast here rather than at the point
     * of use.
     */
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

    /**
=======
>>>>>>> 70ab341a93cda185b5426b47c12600dcb3d90687
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
