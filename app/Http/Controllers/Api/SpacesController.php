<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use App\Models\Workspace;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class SpacesController extends Controller
{
    public const MAX_PER_PAGE = 50;
    public const DEFAULT_PER_PAGE = 15;

    private const CATEGORIES = ['desk', 'private_room', 'full_space'];

    private const HOURLY_PRICE_SQL = '(select min(pricing.price + 0) from pricing inner join units on units.id = pricing.unit_id where units.workspace_id = workspaces.id and pricing.price_type = ?)';

    private const RATING_SQL = '(select avg(reviews.rating) from reviews where reviews.workspace_id = workspaces.id)';

    private const CATEGORY_SQL = '(select units.type from units where units.workspace_id = workspaces.id order by units.id asc limit 1)';

    public function index(Request $request)
    {
        $query = $this->catalogueQuery()
            ->withAvg('reviews', 'rating')
            ->withCount('reviews');

        $this->applyFilters($query, $request);
        $this->applySort($query, $this->stringQuery($request, 'sort', 'newest'));

        $paginator = $query->paginate($this->perPage($request));

        $items = $paginator->getCollection()
            ->map(fn (Workspace $space) => $this->formatSpaceResponse($space))
            ->values()
            ->all();

        return response()->json([
            'data' => $items,
            'spaces' => $items,
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'total' => $paginator->total(),
            'has_more' => $paginator->hasMorePages(),
            'message' => 'تم جلب المساحات بنجاح',
        ], 200);
    }

    public function show($id)
    {
        $space = $this->catalogueQuery()
            ->withAvg('reviews', 'rating')
            ->withCount('reviews')
            ->where('workspaces.id', $id)
            ->first();

        if (! $space) {
            return response()->json(['message' => 'المساحة غير موجودة.'], 404);
        }

        $data = $this->formatSpaceResponse($space);

        return response()->json([
            'space' => $data,
            'data' => $data,
            'message' => 'تم جلب المساحة بنجاح',
        ], 200);
    }

    private function catalogueQuery(): Builder
    {
        return Workspace::query()
            ->where('workspaces.status', 'approved')
            ->where('workspaces.is_active', true)
            ->whereNull('workspaces.deleted_at')
            ->with([
                'images',
                'units.pricing',
                'amenities',
            ]);
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        $search = trim($this->stringQuery($request, 'search'));

        if ($search !== '') {
            $like = '%' . $search . '%';

            $query->where(function (Builder $group) use ($like) {
                $group->where('workspaces.title', 'like', $like)
                    ->orWhere('workspaces.description', 'like', $like)
                    ->orWhere('workspaces.location', 'like', $like);
            });
        }

        $category = $this->stringQuery($request, 'category');

        if (in_array($category, self::CATEGORIES, true)) {
            $query->whereRaw(self::CATEGORY_SQL . ' = ?', [$category]);
        }

        $area = trim($this->stringQuery($request, 'area'));

        if ($area !== '') {
            $query->where('workspaces.location', 'like', '%' . $area . '%');
        }

        $minPrice = $this->numericQuery($request, 'min_price');

        if ($minPrice !== null) {
            $query->whereRaw(self::HOURLY_PRICE_SQL . ' >= ?', [$minPrice]);
        }

        $maxPrice = $this->numericQuery($request, 'max_price');

        if ($maxPrice !== null) {
            $query->whereRaw(self::HOURLY_PRICE_SQL . ' <= ?', [$maxPrice]);
        }

        foreach ($this->requestedAmenities($request) as $name) {
            $query->whereHas('amenities', fn (Builder $q) => $q->where('amenities.name', $name));
        }

        $minRating = $this->numericQuery($request, 'min_rating');

        if ($minRating !== null && $minRating > 0) {
            $query->whereRaw(self::RATING_SQL . ' >= ?', [
                max(0.0, min(5.0, $minRating)),
            ]);
        }
    }

    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'rating' => $query->orderBy('reviews_avg_rating', 'desc'),
            'price_asc' => $query->orderByRaw(self::HOURLY_PRICE_SQL . ' asc', ['hourly']),
            'price_desc' => $query->orderByRaw(self::HOURLY_PRICE_SQL . ' desc', ['hourly']),
            default => $query->orderBy('workspaces.created_at', 'desc'),
        };

        $query->orderBy('workspaces.id', 'desc');
    }

    private function formatSpaceResponse(Workspace $space): array
    {
        $units = $space->units->sortBy('id')->values();

        $availableUnits = $units->where('status', 'available');

        $capacity = (int) $availableUnits->sum(fn (Unit $unit) => (int) $unit->capacity);

        if ($capacity <= 0) {
            $capacity = (int) $units->max(fn (Unit $unit) => (int) $unit->capacity);
        }

        $hourly = $this->hourlyPricing($space);

        $isActive = (bool) $space->is_active;
        $isApproved = $space->status === 'approved';

        $instantBooking = $isActive && $isApproved && $availableUnits->isNotEmpty();

        $gallery = $space->images->pluck('image_url')->values()->toArray();

        $amenityNames = $space->amenities->pluck('name')->values()->toArray();

        $rating = $space->reviews_avg_rating !== null
            ? round((float) $space->reviews_avg_rating, 1)
            : 0.0;

        return [
            'space_id' => (int) $space->id,
            'id' => (int) $space->id,
            'title' => $space->title,
            'description' => $space->description,
            'location' => $space->location,
            'area' => $space->location,
            'category' => $units->first()?->type,
            'image' => $space->images->first()?->image_url,
            'gallery' => $gallery,
            'price_per_hour' => $hourly['price'],
            'price' => $hourly['price'],
            'currency' => $hourly['currency'],
            'capacity' => $capacity,
            'amenities' => $amenityNames,
            'rating' => $rating,
            'review_count' => (int) ($space->reviews_count ?? 0),
            'is_active' => $isActive,
            'instant_booking' => $instantBooking,
            'open_time' => $this->time($space->open_time),
            'close_time' => $this->time($space->close_time),
            'contact_phone' => $space->contact_phone,
            'latitude' => $space->latitude !== null ? (float) $space->latitude : null,
            'longitude' => $space->longitude !== null ? (float) $space->longitude : null,
            'status' => $space->status,
            'created_at' => $space->created_at?->toIso8601String(),
        ];
    }

    private function hourlyPricing(Workspace $space): array
    {
        $hourlyRows = $space->units
            ->flatMap(fn (Unit $unit) => $unit->pricing->where('price_type', 'hourly'));

        if ($hourlyRows->isEmpty()) {
            return ['price' => null, 'currency' => null];
        }

        $cheapest = $hourlyRows
            ->sortBy(fn ($row) => (float) $row->price)
            ->values()
            ->first();

        return [
            'price' => round((float) $cheapest->price, 2),
            'currency' => $cheapest->currency,
        ];
    }

    private function time($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('H:i');
        }

        return substr((string) $value, 0, 5);
    }

    private function stringQuery(Request $request, string $key, string $default = ''): string
    {
        $value = $request->query($key, $default);

        return is_array($value) ? $default : (string) $value;
    }

    private function numericQuery(Request $request, string $key): ?float
    {
        $value = $request->query($key);

        if ($value === null || is_array($value) || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function perPage(Request $request): int
    {
        $perPage = $this->numericQuery($request, 'per_page');

        if ($perPage === null) {
            return self::DEFAULT_PER_PAGE;
        }

        $perPage = (int) floor($perPage);

        if ($perPage < 1) {
            return self::DEFAULT_PER_PAGE;
        }

        return min($perPage, self::MAX_PER_PAGE);
    }

    private function requestedAmenities(Request $request): array
    {
        $raw = $request->query('amenities');

        $names = is_array($raw) ? $raw : explode(',', (string) $raw);

        return collect($names)
            ->filter(fn ($name) => is_string($name) && trim($name) !== '')
            ->map(fn (string $name) => trim($name))
            ->unique()
            ->values()
            ->all();
    }
}