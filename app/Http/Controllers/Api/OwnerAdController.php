<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Services\ImageService;
use App\Traits\OwnerAuthorization;
use App\Traits\ResolvesAdExpiry;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class OwnerAdController extends Controller
{
    use OwnerAuthorization;

    // resolveAdExpiry() and adSchedulePayload() decide what "still running" means.
    // The identical decision lives in PublicAdController for the public banner feed,
    // and a second implementation is a guaranteed divergence: the day one learns a
    // new spelling of `schedule`, the other keeps serving finished campaigns.
    use ResolvesAdExpiry;

    /**
     * How many published rows are read from the database *before* the expiry filter
     * runs, and how many of them may be returned.
     *
     * The expiry lives inside the `schedule` JSON blob, so "is it still live?" is
     * decided in PHP (see ResolvesAdExpiry). Expressing it in SQL would need
     * driver-specific JSON extraction — json_extract() on SQLite, JSON_EXTRACT()
     * with different quoting on MySQL — which would be verified against one driver in
     * the test suite and shipped on the other, and would have to repeat the key
     * precedence rules in SQL too.
     *
     * So the read is bounded instead of filtered: a fixed window is fetched, lapsed
     * rows are dropped, and what is left is cut to the cap. 200 rows is four times the
     * cap, so the list will not look empty merely because the newest campaigns have
     * expired, while the query still can never pull the whole table into memory.
     */
    private const SCAN_LIMIT = 200;

    private const OPEN_LIMIT = 50;

    private const PUBLISHED_LIMIT = 200;

    public function index()
    {
        $this->ensureOwnerRole();

        $ads = Ad::where('user_id', Auth::id())
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($ad) => $this->formatAdResponse($ad));

        return response()->json(['ads' => $ads]);
    }

    /**
     * GET /api/owner/ads/open — the owner's campaigns that are running right now.
     *
     * STRICT SUBSET OF published(): published() is this list plus the campaigns whose
     * expiry has already passed. Both exist because an owner needs both answers —
     * "what is live for customers today" (this one) and "everything I have ever run"
     * (published()). The status filter is identical in both: a draft has not been
     * published and an archived one has been retired, and index() remains the place
     * to see every row regardless of state.
     *
     * Ownership needs no second check: the query is scoped to user_id = Auth::id(),
     * so another owner's ads can never enter the result.
     */
    public function open()
    {
        $this->ensureOwnerRole();

        $now = Carbon::now();

        $formatted = $this->runningAds(Auth::id(), $now)
            ->take(self::OPEN_LIMIT)
            ->values()
            ->map(function (Ad $ad) {
                // formatAdResponse() carries no expiry key, and it cannot: index() and
                // published() never compute one, and a null there would read as "this
                // ad has no end date" rather than "not calculated". The single extra
                // key is added here, on the one endpoint that had to look anyway, so
                // the owner can see when a live campaign ends.
                return $this->formatAdResponse($ad) + [
                    'expires_at' => $this->resolveAdExpiry($ad)?->toIso8601String(),
                ];
            });

        return response()->json([
            'ads' => $formatted,
            // House rule: an ambiguous key is published under both spellings the
            // frontend may read, so a card built against either one renders the list
            // instead of an empty section.
            'open_ads' => $formatted,
            // Rows in THIS response, which the cap can make fewer than the owner has
            // live. It is not a total and must not be used as one.
            'count' => $formatted->count(),
            'message' => 'تم جلب الإعلانات الجارية بنجاح.',
        ]);
    }

    /**
     * GET /api/owner/ads/published — every ad the owner has published, ever.
     *
     * The historical record, and therefore a STRICT SUPERSET of open(): the same
     * status filter with the expiry check removed, so a campaign that ran last week
     * and ended yesterday is still listed here while open() correctly drops it. An
     * owner auditing what has run must not lose a finished campaign, which is exactly
     * what filtering on the expiry would do.
     *
     * The order is newest first with the id as the tie-breaker, matching index() and
     * open(), so the list cannot reshuffle between two requests.
     */
    public function published()
    {
        $this->ensureOwnerRole();

        $ads = Ad::where('user_id', Auth::id())
            ->where('status', 'published')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::PUBLISHED_LIMIT)
            ->get()
            // The same projection index() uses, deliberately: a card that reads one
            // ad out of this list and one out of /owner/ads must see the same fields.
            ->map(fn ($ad) => $this->formatAdResponse($ad));

        return response()->json([
            'ads' => $ads,
            'published_ads' => $ads,
            'count' => $ads->count(),
            'message' => 'تم جلب الإعلانات المنشورة بنجاح.',
        ]);
    }

    /**
     * GET /api/owner/ads/stats — the owner's ad counters.
     *
     * Counts by status (draft / published / archived, the three values the column's
     * enum allows), the summed impressions of those same rows, and `running`, the
     * number of published ads whose expiry has not passed — i.e. the length of
     * GET /api/owner/ads/open, so a card can label itself without counting rows.
     *
     * The totals are read over ALL of the owner's ads; `running` is derived from the
     * same bounded scan open() uses (SCAN_LIMIT rows) and is therefore a floor, not an
     * exact figure, once an owner passes that many published ads.
     *
     * Every query is scoped to user_id = Auth::id(): these counters describe this
     * owner's inventory and cannot be used to infer another owner's.
     */
    public function stats()
    {
        $this->ensureOwnerRole();

        $ownerId = Auth::id();

        $byStatus = [
            'draft' => Ad::where('user_id', $ownerId)->where('status', 'draft')->count(),
            'published' => Ad::where('user_id', $ownerId)->where('status', 'published')->count(),
            'archived' => Ad::where('user_id', $ownerId)->where('status', 'archived')->count(),
        ];

        // Counted rather than array_sum($byStatus): the column is an enum of exactly
        // these three values today, but a legacy row with anything else would silently
        // make the total smaller than the sum.
        $total = Ad::where('user_id', $ownerId)->count();

        // impressions is an integer column, but MySQL's SUM() comes back as a decimal
        // string, so the cast is undone here rather than in the frontend.
        $impressions = (int) Ad::where('user_id', $ownerId)->sum('impressions');

        $running = $this->runningAds($ownerId, Carbon::now())->count();

        $payload = [
            'total' => $total,
            'total_ads' => $total,
            // Flat, so a stat card can read stats.published directly ...
            'draft' => $byStatus['draft'],
            'published' => $byStatus['published'],
            'archived' => $byStatus['archived'],
            // ... and nested, for a card that renders the breakdown as a list.
            'by_status' => $byStatus,
            // The length of GET /api/owner/ads/open. Named `running` rather than
            // `open` on purpose: `open` already names a route in this controller.
            'running' => $running,
            // Both spellings, per the house rule: the bare key is what most of this
            // API's consumers reach for, the suffixed one says which figure it is.
            'impressions' => $impressions,
            'total_impressions' => $impressions,
        ];

        return response()->json([
            'message' => 'تم جلب إحصائيات الإعلانات بنجاح.',
            'stats' => $payload,
            // `data` is the envelope key this API documents for list endpoints
            // (SpecialRequestController, PublicAdController and every admin endpoint
            // return it), mirrored so a client written against either key renders the
            // counters instead of an empty panel.
            'data' => $payload,
        ]);
    }

    /**
     * The owner's published ads that are still running at $now.
     *
     * Shared by open() and stats() so the two can never disagree about which ads are
     * live — the same reason the expiry itself is shared. Bounded by SCAN_LIMIT before
     * the PHP-side expiry filter runs, for the driver-portability reason documented on
     * that constant.
     */
    private function runningAds($ownerId, Carbon $now)
    {
        return Ad::where('user_id', $ownerId)
            // The single most important condition: a draft has not gone out and an
            // archived one has been retired, so neither may be reported as running.
            ->where('status', 'published')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::SCAN_LIMIT)
            ->get()
            ->filter(fn (Ad $ad) => $this->adIsRunning($ad, $now));
    }

    public function store(Request $request)
    {
        $this->ensureOwnerRole();

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'link' => 'nullable|string|url',
            'image' => 'nullable|string',
            'target' => 'required|string',
            'schedule' => 'nullable|json',
        ]);

        $imagePath = null;
        if (!empty($validated['image'])) {
            $imagePath = ImageService::storeBase64Image($validated['image'], 'ads');
        }

        $ad = Ad::create([
            'user_id' => Auth::id(),
            'title' => $validated['title'],
            'description' => $validated['description'],
            'link' => $validated['link'],
            'image' => $imagePath,
            'target' => $validated['target'],
            'status' => 'draft',
            'schedule' => $validated['schedule'],
        ]);

        return response()->json([
            'message' => 'تم إنشاء الإعلان.',
            'ad' => $this->formatAdResponse($ad),
        ], 201);
    }

    /**
     * `int $id` rather than `string $id`: the route declares ->whereNumber('ad'), so
     * the segment is digits only, and a PHP type of int makes the same guarantee at
     * the method boundary. Laravel hands route parameters over as raw strings, but
     * these files are not in strict_types mode, so "12" coerces to 12; anything
     * non-numeric never gets this far because the route constraint rejects it first.
     */
    public function update(Request $request, int $id)
    {
        $this->ensureOwnerRole();
        $ad = $this->ensureOwnsAd($id);

        $validated = $request->validate([
            'title' => 'string|max:255',
            'description' => 'string',
            'link' => 'nullable|string|url',
            'image' => 'nullable|string',
            'target' => 'string',
            'status' => 'in:draft,published,archived',
            'schedule' => 'nullable|json',
        ]);

        $imagePath = null;
        if (isset($validated['image']) && !empty($validated['image'])) {
            $imagePath = ImageService::storeBase64Image($validated['image'], 'ads');
        }

        $updateData = [
            'title' => $validated['title'] ?? $ad->title,
            'description' => $validated['description'] ?? $ad->description,
            'link' => $validated['link'] ?? $ad->link,
            'target' => $validated['target'] ?? $ad->target,
            'status' => $validated['status'] ?? $ad->status,
            'schedule' => $validated['schedule'] ?? $ad->schedule,
        ];

        if ($imagePath) {
            $updateData['image'] = $imagePath;
        }

        $ad->update($updateData);

        return response()->json([
            'message' => 'تم تحديث الإعلان.',
            'ad' => $this->formatAdResponse($ad),
        ]);
    }

    /**
     * `int $id` for the same reason as update(): ->whereNumber('ad') on the route.
     */
    public function destroy(int $id)
    {
        $this->ensureOwnerRole();
        $ad = $this->ensureOwnsAd($id);

        $ad->delete();

        return response()->json(['message' => 'تم حذف الإعلان.']);
    }

    /**
     * `int $id` for the same reason as update(): ->whereNumber('ad') on the route.
     */
    public function publish(Request $request, int $id)
    {
        $this->ensureOwnerRole();
        $ad = $this->ensureOwnsAd($id);

        $ad->update([
            'status' => 'published',
            'sent_at' => now(),
        ]);

        return response()->json([
            'message' => 'تم نشر الإعلان.',
            'ad' => $this->formatAdResponse($ad),
        ]);
    }

    private function formatAdResponse(Ad $ad)
    {
        return [
            'id' => $ad->id,
            'ad_id' => $ad->id,
            'title' => $ad->title,
            'description' => $ad->description,
            'notes' => $ad->description,
            'link' => $ad->link,
            'url' => $ad->link,
            'image' => $ad->image,
            'target' => $ad->target,
            'space_id' => $ad->space_id,
            'space' => $ad->space_id,
            'status' => $ad->status,
            'created_at' => $ad->created_at?->toIso8601String(),
            'created' => $ad->created_at?->toIso8601String(),
            'sent_at' => $ad->sent_at?->toIso8601String(),
            'impressions' => $ad->impressions,
            'impressions_count' => $ad->impressions,
            'schedule' => $ad->schedule,
        ];
    }
}
