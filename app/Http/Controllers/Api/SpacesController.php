<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use App\Models\Workspace;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The PUBLIC spaces catalogue.
 *
 * Everything here is reachable without a Sanctum token: these are the endpoints
 * an anonymous visitor hits before signing up, so neither the list nor the show
 * may ever answer 401. Owners keep their own private view of their listings on
 * /api/owner/spaces, which is not touched by this controller.
 *
 * There is no `Space` model and no `spaces` table in this schema: a space is a
 * `Workspace` (table `workspaces`, PK `id`). Bookings hang off `Unit`, the
 * hourly price lives in `Pricing`, and the amenities live on the
 * `workspace_amenity` pivot. Every field below is DERIVED from those existing
 * columns — this controller adds no columns and writes no data.
 */
class SpacesController extends Controller
{
    /**
     * A catalogue page bigger than this is never served.
     *
     * Hard cap on purpose: `per_page` arrives from the browser, and other
     * endpoints in this repo page with it uncapped, so ?per_page=100000 pulls
     * the whole table into memory. Clamping here is the difference between a
     * slow page and an OOM on a large catalogue.
     */
    public const MAX_PER_PAGE = 50;

    public const DEFAULT_PER_PAGE = 15;

    /**
     * The only accepted values of ?category=.
     *
     * There is no `category` column in this schema. The real category dimension
     * that does exist is `units.type`, which is an enum of exactly these three
     * values, so the filter is validated against them instead of against a
     * free-text column that never existed.
     */
    private const CATEGORIES = ['desk', 'private_room', 'full_space'];

    /**
     * SQL for a workspace's hourly price: the cheapest hourly rate across all
     * of its units.
     *
     * Two deliberate details:
     *
     * - `price_type = ?` is a binding and the sub-select is filtered to hourly,
     *   because a unit carries three price rows (hourly / daily / monthly) and
     *   the contract field is the HOURLY one. Unfiltered, a daily rate of 10
     *   next to an hourly rate of 120 would answer the listing with 10.
     * - `price + 0` is the portable numeric cast. `pricing.price` is a string
     *   column, and MySQL (production) and SQLite (tests) both need it spelled
     *   the same way. `CAST(x AS REAL)` only exists on MySQL >= 8.0.17, and
     *   `CAST(x AS DECIMAL(10,2))` is a parse error on SQLite, so neither is
     *   usable here. `+ 0` evaluates numerically on both drivers.
     *
     * Used for the price filter AND the price sort, so what the filter matches
     * and what the projection renders can never disagree.
     */
    private const HOURLY_PRICE_SQL = '(select min(pricing.price + 0) from pricing inner join units on units.id = pricing.unit_id where units.workspace_id = workspaces.id and pricing.price_type = ?)';

    /**
     * SQL for a workspace's review average.
     *
     * `withAvg()` puts the same average on the row as the alias
     * `reviews_avg_rating`, but a SELECT alias cannot be referenced from WHERE:
     * MySQL allows it as an extension and SQLite rejects it outright. The
     * sorting branch may use the alias (ORDER BY may reference an output column
     * on both drivers); the filtering branch may not, so it repeats the average
     * as a correlated sub-select. It also keeps `paginate()`'s count query —
     * which drops the select list entirely — safe from a HAVING on an alias.
     */
    private const RATING_SQL = '(select avg(reviews.rating) from reviews where reviews.workspace_id = workspaces.id)';

    /**
     * SQL for a workspace's category: the type of its first unit.
     *
     * Matching the *first* unit (lowest id) is what makes `?category=desk`
     * agree with the `category` the projection renders. `whereHas('units', …)`
     * would be simpler, but a workspace holding both a desk and a private room
     * would then be returned by two different category filters while rendering
     * only one of them.
     */
    private const CATEGORY_SQL = '(select units.type from units where units.workspace_id = workspaces.id order by units.id asc limit 1)';

    /**
     * GET /api/spaces — the public catalogue.
     *
     * Every query parameter is optional and an unrecognised one is ignored
     * rather than rejected: this is a URL a visitor pastes and shares, and a
     * browser that appends its own cache-busting parameters must still get a
     * page back. An unsupported *value* for a known parameter is likewise
     * ignored (see applySort / applyFilters) — no filter is not an error.
     */
    public function index(Request $request)
    {
        $query = $this->catalogueQuery()
            // `reviews_avg_rating` (the ORDER BY target for ?sort=rating) and
            // `reviews_count`. Both are sub-selects, so they cost nothing extra
            // per row and neither is an N+1.
            ->withAvg('reviews', 'rating')
            ->withCount('reviews');

        $this->applyFilters($query, $request);
        $this->applySort($query, $this->stringQuery($request, 'sort', 'newest'));

        // `paginate()` resolves the page from ?page= itself, so ?page=1 and
        // ?page=2 really do return different rows, and total() is the count of
        // the FILTERED set — not of the whole table.
        $paginator = $query->paginate($this->perPage($request));

        $items = $paginator->getCollection()
            ->map(fn (Workspace $space) => $this->formatSpaceResponse($space))
            ->values()
            ->all();

        // The frontend reads `res.data.data || res.data || res.spaces`, so the
        // items are published under two spellings: `data` (what the tolerant
        // reader picks up first) and `spaces` (the explicit alias). Both point
        // at the same array. `has_more` is required — the frontend does NOT
        // derive it from last_page, and without it the "load more" button
        // disappears while more pages remain.
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

    /**
     * GET /api/spaces/{id} — one public space.
     *
     * The route carries `->whereNumber('id')`, so a non-numeric segment is
     * rejected by the router before this method runs.
     */
    public function show($id)
    {
        // The visibility rule is folded into the lookup itself (catalogueQuery),
        // so an unapproved / inactive / soft-deleted space is not found here at
        // all.
        $space = $this->catalogueQuery()
            ->withAvg('reviews', 'rating')
            ->withCount('reviews')
            ->where('workspaces.id', $id)
            ->first();

        if (! $space) {
            // 404, and deliberately NOT 403 and never 401.
            //
            // 401/403 would be wrong: the space is public, so a visitor is never
            // forbidden from reading it. A 403 would also be a leak — it tells
            // the caller "this id exists but you may not see it", which is
            // exactly the information an unapproved listing must not give away.
            // A single 404 covers "no such space" and "not visible", and the
            // message is the same complete Arabic sentence in both cases.
            return response()->json(['message' => 'المساحة غير موجودة.'], 404);
        }

        $data = $this->formatSpaceResponse($space);

        return response()->json([
            // `space` is the contract shape; `data` mirrors it because the
            // frontend's tolerant reader falls back to `res.data` and would
            // otherwise render an empty detail page. Same dual-key pattern as
            // SpecialRequestController's `requests` / `data.requests`.
            'space' => $data,
            'data' => $data,
            'message' => 'تم جلب المساحة بنجاح',
        ], 200);
    }

    /**
     * The base query: what an anonymous visitor is allowed to see, plus every
     * relation the projection reads (eager-loaded, so the list is 1 + N queries
     * and never one query per row).
     *
     * Indexes serving this query are added by
     * database/migrations/2026_10_08_090000_add_listing_indexes_to_workspaces_table.php:
     *
     * - workspaces (status, is_active, created_at) covers the two equality
     *   predicates below and then answers the default `newest` sort
     *   (`created_at desc`) from the trailing column of the same index, so the
     *   listing is an index range scan with no filesort for a filter-less page.
     * - units (workspace_id, status) serves the `units.workspace_id =
     *   workspaces.id` correlation inside the price and category sub-selects,
     *   and the `status = 'available'` half of the capacity /
     *   instant_booking derivation.
     * - pricing (unit_id, price_type) serves the join in the hourly-price
     *   sub-select, narrowed to price_type = 'hourly'.
     * - The review average rides the foreign key index that
     *   `reviews.workspace_id` already got from ->constrained().
     */
    private function catalogueQuery(): Builder
    {
        return Workspace::query()
            // Visibility rule for the anonymous visitor: a space is public only
            // once an admin approved it AND the owner switched it on.
            ->where('workspaces.status', 'approved')
            ->where('workspaces.is_active', true)
            // The Workspace model imports SoftDeletes but never declares
            // `use SoftDeletes;`, so Eloquent does NOT add `deleted_at is null`
            // for us even though the column exists. Without this line a
            // soft-deleted space would keep showing up in the public catalogue
            // and on its public detail page. Asserted by
            // SpaceCatalogTest::test_pending_inactive_and_soft_deleted_spaces_are_excluded.
            ->whereNull('workspaces.deleted_at')
            ->with([
                'images',
                // units.pricing carries both derivations that have to read the
                // rows: the hourly price (to pick the hourly row, never the
                // daily one) and the capacity.
                'units.pricing',
                'amenities',
            ]);
    }

    /**
     * Apply every catalogue filter. Each one narrows the query on its own; a
     * filter that is accepted but silently ignored is the exact bug that made
     * the marketplace unusable, so each clause below is covered by a test.
     */
    private function applyFilters(Builder $query, Request $request): void
    {
        // title OR description OR location, case-insensitively.
        //
        // Case folding is left to the database: MySQL's default collation is
        // already case-insensitive and SQLite's LIKE is case-insensitive for
        // ASCII; Arabic has no case to fold, which is the language most of the
        // catalogue is written in.
        $search = trim($this->stringQuery($request, 'search'));

        if ($search !== '') {
            $like = '%' . $search . '%';

            // Wrapped in its own group, otherwise the OR would escape the
            // visibility conditions and expose pending / inactive listings.
            $query->where(function (Builder $group) use ($like) {
                $group->where('workspaces.title', 'like', $like)
                    ->orWhere('workspaces.description', 'like', $like)
                    ->orWhere('workspaces.location', 'like', $like);
            });
        }

        // Validated against the enum; anything else is simply not a filter.
        $category = $this->stringQuery($request, 'category');

        if (in_array($category, self::CATEGORIES, true)) {
            $query->whereRaw(self::CATEGORY_SQL . ' = ?', [$category]);
        }

        // `area` has no column of its own; `location` is the only place
        // dimension this schema stores, so both are matched against it.
        $area = trim($this->stringQuery($request, 'area'));

        if ($area !== '') {
            $query->where('workspaces.location', 'like', '%' . $area . '%');
        }

        // Inclusive on both ends, against the same hourly price the response
        // renders.
        $minPrice = $this->numericQuery($request, 'min_price');

        if ($minPrice !== null) {
            $query->whereRaw(self::HOURLY_PRICE_SQL . ' >= ?', [$minPrice]);
        }

        $maxPrice = $this->numericQuery($request, 'max_price');

        if ($maxPrice !== null) {
            $query->whereRaw(self::HOURLY_PRICE_SQL . ' <= ?', [$maxPrice]);
        }

        // AND semantics: every listed amenity has to be on the space. One
        // whereHas() per name rather than a single `whereIn` + count(), because
        // the count() variant compiles to an aggregate HAVING inside an EXISTS
        // and that shape is not worth the portability risk for a handful of
        // amenities.
        foreach ($this->requestedAmenities($request) as $name) {
            $query->whereHas('amenities', fn (Builder $q) => $q->where('amenities.name', $name));
        }

        $minRating = $this->numericQuery($request, 'min_rating');

        // 0 is the floor of the rating scale and means "no filter". Without this
        // guard `?min_rating=0` would compare against a NULL average for every
        // space nobody has reviewed yet and quietly drop all of them from the
        // catalogue. A space with no reviews has no average, so `avg(rating) >=
        // 1` is NULL and never true — which is the correct outcome for a real
        // minimum.
        if ($minRating !== null && $minRating > 0) {
            $query->whereRaw(self::RATING_SQL . ' >= ?', [
                max(0.0, min(5.0, $minRating)),
            ]);
        }
    }

    /**
     * Order the catalogue.
     *
     * Every branch ends with the same `workspaces.id desc` tie-breaker. It is
     * not cosmetic: many rows share a created_at (any page load inserts several
     * at once) and without a unique tie-break the database is free to return
     * the same row on page 1 and page 2, or to skip one entirely between them.
     */
    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            // An alias from withAvg(); ORDER BY may reference a select alias on
            // MySQL and on SQLite alike. Unrated spaces (NULL) sort last.
            'rating' => $query->orderBy('reviews_avg_rating', 'desc'),
            'price_asc' => $query->orderByRaw(self::HOURLY_PRICE_SQL . ' asc', ['hourly']),
            'price_desc' => $query->orderByRaw(self::HOURLY_PRICE_SQL . ' desc', ['hourly']),
            // `newest`, and the fallback for every unrecognised value: an
            // unsupported sort is not an error, it is the default sort.
            default => $query->orderBy('workspaces.created_at', 'desc'),
        };

        $query->orderBy('workspaces.id', 'desc');
    }

    /**
     * Project a workspace into the catalogue contract.
     *
     * The dual-spelling keys (`space_id` + `id`, `price_per_hour` + `price`) are
     * a deliberate, documented house convention: the frontend is inconsistent
     * about which name it reads, and one missing key renders a blank cell with
     * no error anywhere. Emitting both under the same value is cheaper than
     * hunting for the offending screen.
     *
     * There is no ApiResource in this app; every controller builds its own
     * projection by hand, like this one.
     */
    private function formatSpaceResponse(Workspace $space): array
    {
        // Ordered by id so `category` (the first unit's type) is deterministic,
        // and so it matches what CATEGORY_SQL matches in the filter.
        $units = $space->units->sortBy('id')->values();

        $availableUnits = $units->where('status', 'available');

        // Capacity is the sum of the bookable units, with a fallback to the
        // largest single unit when nothing is marked available — otherwise a
        // workspace whose units are all temporarily unavailable would render a
        // capacity of 0, which reads as "too small" rather than "not bookable".
        // units.capacity is a string column, so it is cast per unit.
        $capacity = (int) $availableUnits->sum(fn (Unit $unit) => (int) $unit->capacity);

        if ($capacity <= 0) {
            $capacity = (int) $units->max(fn (Unit $unit) => (int) $unit->capacity);
        }

        $hourly = $this->hourlyPricing($space);

        $isActive = (bool) $space->is_active;
        $isApproved = $space->status === 'approved';

        // Derived, not stored: a space is instantly bookable when it is live,
        // approved, and has at least one unit actually available to book.
        $instantBooking = $isActive && $isApproved && $availableUnits->isNotEmpty();

        $gallery = $space->images->pluck('image_url')->values()->toArray();

        // Names, not ids: the frontend renders amenity chips from strings.
        $amenityNames = $space->amenities->pluck('name')->values()->toArray();

        // withAvg() yields a decimal on MySQL and a float on SQLite; both are
        // rounded to one decimal so the two drivers agree on the wire.
        $rating = $space->reviews_avg_rating !== null
            ? round((float) $space->reviews_avg_rating, 1)
            : 0.0;

        return [
            'space_id' => (int) $space->id,
            'id' => (int) $space->id,
            'title' => $space->title,
            'description' => $space->description,
            'location' => $space->location,
            // `area` is derived from location: this schema stores no separate
            // neighbourhood / district column.
            'area' => $space->location,
            'category' => $units->first()?->type,
            // Null-safe navigation throughout: a space may legitimately have no
            // images, no pricing and no reviews yet.
            'image' => $space->images->first()?->image_url,
            // An array, never a Collection, or it serialises as {} and the
            // frontend's gallery .map() throws.
            'gallery' => $gallery,
            // A float, not the model's decimal:2 string. A decimal cast
            // serialises as "50.00" and the frontend sorts / compares it as a
            // number.
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

    /**
     * The workspace's hourly price and its currency.
     *
     * Mirrors HOURLY_PRICE_SQL exactly — the cheapest hourly row across all of
     * the workspace's units — so the number the filter matched is the number
     * the response renders. `price_type` is compared here for the same reason it
     * is a binding there: picking the first pricing row of a unit would return
     * its daily or monthly rate whenever that row came first.
     */
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
            // pricing.price casts to decimal:2, which is a string on the model;
            // undone here so the JSON carries a number.
            'price' => round((float) $cheapest->price, 2),
            'currency' => $cheapest->currency,
        ];
    }

    /**
     * `time` columns read back as "08:00:00" on MySQL and as "08:00" or
     * "08:00:00" on SQLite depending on the build, so the value is trimmed to
     * five characters and the contract stays the same on both drivers.
     */
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

    /**
     * Read a query parameter as a string.
     *
     * An array (`?search[]=x`, `?sort[]=rating`) is treated as "not supplied"
     * rather than cast: `(string) $array` raises an array-to-string conversion
     * warning, and Laravel's error handler turns warnings into ErrorException,
     * so a hand-edited URL would 500 the marketplace.
     */
    private function stringQuery(Request $request, string $key, string $default = ''): string
    {
        $value = $request->query($key, $default);

        return is_array($value) ? $default : (string) $value;
    }

    /**
     * Read a numeric query parameter, or null when it is absent or not a number.
     *
     * Null means "do not apply this filter" — a missing or junk value must never
     * become a 422 on a catalogue URL.
     */
    private function numericQuery(Request $request, string $key): ?float
    {
        $value = $request->query($key);

        if ($value === null || is_array($value) || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    /**
     * ?per_page=, clamped into [1, MAX_PER_PAGE].
     */
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

    /**
     * ?amenities=internet,ac — CSV, and `?amenities[]=internet&amenities[]=ac`
     * is accepted too because that is how a repeated query parameter arrives
     * from a form serialiser. Blank entries are dropped and duplicates
     * collapsed, so `?amenities=internet,internet` is one condition, not two.
     */
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