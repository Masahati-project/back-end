<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Amenity;
use App\Models\Booking;
use App\Models\Pricing;
use App\Models\Unit;
use App\Models\Workspace;
use App\Models\WorkspaceImage;
use App\Services\ImageService;
use App\Services\SpaceDocumentService;
use App\Services\SpaceDocumentStorage;
use App\Traits\OwnerAuthorization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

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
            'image' => $request->hasFile('image')
                ? 'file|image|mimes:jpg,jpeg,png,gif,webp|max:10240'
                : 'nullable|string',
            'docs' => 'required|file|mimes:pdf,png,jpg,jpeg,webp|max:10240',
            'open_time' => 'required',
            'close_time' => 'required',
            'contact_phone' => 'required',
        ]);

        $proofPath = SpaceDocumentStorage::store($request->file('docs'), 'documents');

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

        // The proof sent with the create form is also a document row, so the
        // owner's document list and the admin review queue see the same file
        // instead of only the legacy `space_document_url` column.
        $space->spaceDocuments()->create([
            'user_id' => Auth::id(),
            'proof_document_url' => $proofPath,
            'status' => 'pending',
        ]);

        $imagePath = $this->resolveIncomingImageValue($request, $validated['image'] ?? null, 'spaces');

        if ($imagePath !== null) {
            WorkspaceImage::create([
                'workspace_id' => $space->id,
                'image_url' => $imagePath,
            ]);
        }

        $unit = Unit::create([
            'workspace_id' => $space->id,
            'type' => 'full_space',
            'capacity' => $validated['capacity'],
            'has_wifi' => $validated['internet'],
            'has_power' => $validated['power'],
            'status' => 'available',
        ]);

        Pricing::create([
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

        // Multipart bodies carry string booleans and a JSON-encoded amenities
        // string, exactly like create — PATCH ('POST' + `_method=PATCH`) and PUT
        // speak the same wire format.
        $this->normalizeListingPayload($request);

        // Item 3, rule 5: a field outside the edit contract is a 422 with a
        // clear reason, never a silent ignore. Silent ignoring is precisely how
        // hours and phone used to vanish with a "saved" answer.
        $this->rejectFieldsOutsideTheEditContract($request);

        $validated = $request->validate($this->editRules($request, $space));

        $this->guardCloseAfterOpen($space, $validated);

        $this->applyListingEdit($request, $space, $validated);

        return response()->json([
            'message' => 'تم تحديث المساحة.',
            'space' => $this->formatSpaceResponse($space),
        ]);
    }

    /**
     * The fields the edit form is allowed to send. Every alias the API returns
     * on read is also accepted on write, so a client that round-trips the last
     * response body (the PUT fallback) is not punished for the extra keys.
     */
    private const EDIT_FIELDS = [
        'title',
        'description',
        'location',
        'lat',
        'lng',
        'price_per_hour',
        'capacity',
        'open_time',
        'close_time',
        'contact_phone',
        'amenities',
        'internet',
        'power',
        'image',
    ];

    private const EDIT_FIELD_ALIASES = [
        'latitude' => 'lat',
        'longitude' => 'lng',
        'wifi' => 'internet',
        'has_internet' => 'internet',
        'electricity' => 'power',
        'has_power' => 'power',
    ];

    private const IMAGE_MAX_KILOBYTES = 10240;

    /**
     * Map the response's alternate key names onto the canonical edit fields so
     * validation sees one shape. Runs after normalizeListingPayload so a
     * multipart string alias ("true") is already a real boolean.
     */
    private function canonicalizeEditFields(Request $request): void
    {
        $merge = [];

        foreach (self::EDIT_FIELD_ALIASES as $alias => $canonical) {
            if ($request->exists($alias) && ! $request->exists($canonical)) {
                $merge[$canonical] = $request->input($alias);
            }
        }

        if ($merge !== []) {
            $request->merge($merge);
        }
    }

    /**
     * Item 3, rule 5: fields the contract does not allow must fail loudly.
     * `status`, `is_active` and `approved_at` belong to the admin, and every
     * unknown key comes back as a per-field error the frontend can render.
     */
    private function rejectFieldsOutsideTheEditContract(Request $request): void
    {
        $this->canonicalizeEditFields($request);

        $allowed = array_merge(
            self::EDIT_FIELDS,
            array_keys(self::EDIT_FIELD_ALIASES),
            ['_method', 'docs'] // transport plumbing, not listing data
        );

        $unknown = collect(array_keys($request->all()))
            ->reject(fn ($field) => in_array($field, $allowed, true))
            ->unique()
            ->values();

        if ($unknown->isEmpty()) {
            return;
        }

        $message = 'هذا الحقل غير مدعوم في تعديل المساحة. الحقول المسموحة: '
            .implode('، ', self::EDIT_FIELDS)
            .'.';

        throw ValidationException::withMessages(
            $unknown->mapWithKeys(fn ($field) => [$field => $message])->all()
        );
    }

    /**
     * All edit rules are optional: a key the client does not send leaves the
     * stored value untouched (Item 3, rule 2). A key the client sends as null
     * is only accepted for fields whose "empty" state exists; the columns that
     * cannot be empty reject null (Item 3, rule 3 within column limits).
     */
    private function editRules(Request $request, Workspace $space): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'lat' => ['sometimes', 'numeric', 'between:-90,90'],
            'lng' => ['sometimes', 'numeric', 'between:-180,180'],
            'price_per_hour' => ['sometimes', 'numeric', 'min:0'],
            'capacity' => ['sometimes', 'integer', 'min:1'],
            'open_time' => ['sometimes', 'string', $this->clockFormatRule()],
            'close_time' => ['sometimes', 'string', $this->clockFormatRule()],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:255'],
            'amenities' => ['sometimes', 'nullable', 'array'],
            'internet' => ['sometimes', 'boolean'],
            'power' => ['sometimes', 'boolean'],
            'image' => $request->hasFile('image')
                ? ['file', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:'.self::IMAGE_MAX_KILOBYTES]
                : ['sometimes', 'nullable', 'string'],
        ];
    }

    /**
     * Accepts the HH:mm the frontend normalizes to (and is tolerant of
     * "H:mm" and "HH:mm:ss" leftovers), rejects everything else.
     */
    private function clockFormatRule(): \Closure
    {
        return function ($attribute, $value, $fail) {
            if (! is_string($value) || preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', trim($value)) !== 1) {
                $fail("The $attribute field must be a 24-hour time in the HH:mm format, e.g. 09:00.");
            }
        };
    }

    /**
     * `time` columns read back as "08:00:00" on MySQL and "08:00" or "08:00:00"
     * on SQLite — compare the HH:MM prefix, never the raw driver string.
     */
    private function minutesOfDay($time): int
    {
        $clock = substr(trim((string) $time), 0, 5);

        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $clock, $matches)) {
            return -1;
        }

        return ((int) $matches[1]) * 60 + (int) $matches[2];
    }

    /**
     * The stored close time must be after the stored-open one — including when
     * only one of the pair was sent this round.
     */
    private function guardCloseAfterOpen(Workspace $space, array $validated): void
    {
        $open = $validated['open_time'] ?? (filled($space->open_time) ? substr(trim((string) $space->open_time), 0, 5) : null);
        $close = $validated['close_time'] ?? (filled($space->close_time) ? substr(trim((string) $space->close_time), 0, 5) : null);

        if ($open === null || $close === null) {
            return;
        }

        $openMinutes = $this->minutesOfDay($open);
        $closeMinutes = $this->minutesOfDay($close);

        if ($openMinutes < 0 || $closeMinutes < 0) {
            return;
        }

        if ($closeMinutes <= $openMinutes) {
            throw ValidationException::withMessages([
                'close_time' => 'close_time must be after open_time.',
            ]);
        }
    }

    /**
     * Touches exactly the fields the request carried. A field not mentioned
     * keeps its value; there is no "fallback to the stored value" merge that
     * could smear a stale column over a fresh one.
     */
    private function applyListingEdit(Request $request, Workspace $space, array $validated): void
    {
        $updates = [];

        foreach (['title' => 'title', 'description' => 'description', 'lat' => 'latitude', 'lng' => 'longitude'] as $field => $column) {
            if (array_key_exists($field, $validated)) {
                $updates[$column] = $validated[$field];
            }
        }

        // NOT NULL string columns: an explicit null empties them (Item 3, rule 3).
        foreach (['location', 'contact_phone'] as $field) {
            if (array_key_exists($field, $validated)) {
                $updates[$field] = $validated[$field] ?? '';
            }
        }

        foreach (['open_time', 'close_time'] as $field) {
            if (array_key_exists($field, $validated)) {
                $updates[$field] = $this->normalizeClock((string) $validated[$field]);
            }
        }

        if ($updates !== []) {
            $space->update($updates);
        }

        if (array_key_exists('price_per_hour', $validated)) {
            $this->saveHourlyPrice($space, $validated['price_per_hour']);
        }

        $unitUpdates = [];
        if (array_key_exists('capacity', $validated)) {
            $unitUpdates['capacity'] = $validated['capacity'];
        }
        if (array_key_exists('internet', $validated)) {
            $unitUpdates['has_wifi'] = $validated['internet'];
        }
        if (array_key_exists('power', $validated)) {
            $unitUpdates['has_power'] = $validated['power'];
        }

        if ($unitUpdates !== [] && ($unit = $space->units()->first())) {
            $unit->update($unitUpdates);
        }

        // Amenities are a full sync; null and [] are both an intentional empty
        // set (Item 3, rules 3-4).
        if (array_key_exists('amenities', $validated)) {
            $ids = collect($validated['amenities'] ?? [])
                ->map(fn ($amenity) => is_array($amenity) ? ($amenity['name'] ?? $amenity['key'] ?? null) : $amenity)
                ->filter()
                ->map(fn ($name) => $this->resolveAmenityId((string) $name))
                ->unique()
                ->values()
                ->all();

            $space->amenities()->sync($ids);
        }

        // A file wins (Item 4). A string is either a data-URL to upload or an
        // already-hosted URL, which counts as "unchanged". An explicit null
        // clears the gallery (Item 3, rule 3).
        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('spaces', 'cloudinary');

            $this->replaceSpaceImage($space, $path);
        } elseif (array_key_exists('image', $validated)) {
            $value = $validated['image'];

            if ($value === null || trim((string) $value) === '') {
                $space->images()->delete();

                return;
            }

            $path = filter_var($value, FILTER_VALIDATE_URL)
                ? $value
                : ImageService::storeBase64Image($value, 'spaces');

            if ($path !== null) {
                $this->replaceSpaceImage($space, $path);
            }
        }
    }

    private function replaceSpaceImage(Workspace $space, string $path): void
    {
        $space->images()->delete();

        WorkspaceImage::create([
            'workspace_id' => $space->id,
            'image_url' => $path,
        ]);
    }

    /**
     * One inbound `image` value, three shapes: an uploaded file (the multipart
     * create/edit path), an already-hosted URL (counts as "unchanged" and is
     * kept verbatim), or a base64/data-URL string which is uploaded here.
     *
     * Returns the address that belongs in the image column — a stored path or
     * the URL as-is — or null when there is nothing to store.
     */
    private function resolveIncomingImageValue(Request $request, $value, string $directory): ?string
    {
        if ($request->hasFile('image')) {
            return $request->file('image')->store($directory, 'cloudinary');
        }

        $value = is_string($value) ? trim($value) : null;

        if ($value === null || $value === '') {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return $value;
        }

        return ImageService::storeBase64Image($value, $directory);
    }

    private function saveHourlyPrice(Workspace $space, $price): void
    {
        $unit = $space->units()->first();

        if (! $unit) {
            return;
        }

        $pricing = Pricing::where('unit_id', $unit->id)
            ->where('price_type', 'hourly')
            ->first();

        if ($pricing) {
            $pricing->update(['price' => $price]);
        } else {
            Pricing::create([
                'unit_id' => $unit->id,
                'price_type' => 'hourly',
                'price' => $price,
                'currency' => 'ILS',
            ]);
        }
    }

    /**
     * "9:00" → "09:00"; "09:00:00" → "09:00". Storage keeps a normalised clock
     * so every read — including the ones outside this controller — is uniform.
     */
    private function normalizeClock(string $value): string
    {
        $clock = trim($value);

        if (str_contains($clock, ':')) {
            $pieces = explode(':', $clock, 3) + [null, null];

            return sprintf('%02d:%02d', (int) $pieces[0], (int) $pieces[1]);
        }

        return $clock;
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

        // Full delivery URLs, built from the stored paths — and legacy rows that
        // hold an upload flag ("1") come out as null rather than a broken link.
        $gallery = $space->images
            ->pluck('image_url')
            ->map(fn ($path) => ImageService::url($path))
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $image = $gallery[0] ?? null;

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
            // `time` columns read back as "08:00:00" on MySQL and "08:00" or
            // "08:00:00" on SQLite; the contract is HH:mm, so truncate.
            'open_time' => $space->open_time !== null ? substr(trim((string) $space->open_time), 0, 5) : null,
            'close_time' => $space->close_time !== null ? substr(trim((string) $space->close_time), 0, 5) : null,
            'contact_phone' => $space->contact_phone,
            'image' => $image,
            'gallery' => $gallery,
            'space_document_url' => SpaceDocumentService::url($space->space_document_url),
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
