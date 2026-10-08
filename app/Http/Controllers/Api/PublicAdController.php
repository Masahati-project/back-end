<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ad;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The public banner feed: GET /api/ads/open
 *
 * Until now the ads page read a hardcoded empty array from the client, so no
 * customer has ever actually seen a published ad. This endpoint is what makes
 * the strip real, and the point of it is the *server side* of the rules:
 * a draft or archived ad must never reach a client, and an ad whose expiry has
 * passed must be dropped here rather than being left for the page's own
 * client-side date filter to catch. The frontend filter is a convenience; this
 * is the enforcement.
 */
class PublicAdController extends Controller
{
    /**
     * Roles allowed to read the feed.
     *
     * `admin` is deliberately not in the list: this is the banner strip shown
     * inside the app to signed-in customers and space owners, and an admin
     * account is not one of its audiences. An admin reads its own rows through
     * /api/owner/ads, so refusing it here leaks nothing.
     */
    private const ALLOWED_ROLES = ['customer', 'space_owner'];

    /**
     * Hard cap on the number of ads one request may return.
     *
     * The feed is a rotating banner strip and no client renders more than a
     * handful of entries, so 50 is already generous. The cap keeps a single call
     * from serialising an entire ads table -- every row eager loads an owner and
     * a workspace -- into memory and onto the wire.
     */
    private const FEED_LIMIT = 50;

    /**
     * How many published rows are read from the database *before* the expiry
     * filter runs.
     *
     * The expiry lives inside the `schedule` JSON blob, so the "is it still
     * live?" decision is taken in PHP (see resolveExpiry()). Expressing it in
     * SQL would require driver-specific JSON extraction -- json_extract() on
     * SQLite's json1 build, JSON_EXTRACT() with different quoting on MySQL --
     * and the test suite runs on SQLite while production runs on MySQL, so a raw
     * JSON predicate would be verified against one driver and shipped on the
     * other. It would also have to repeat the key-precedence rules in SQL.
     *
     * So the read is bounded instead of the filter: fetch a fixed window, drop
     * what has lapsed, then cut what is left to FEED_LIMIT. 200 is four times the
     * cap -- enough headroom that the feed will not look empty just because the
     * newest rows happened to have expired, while still guaranteeing the query
     * can never pull the whole table into memory.
     */
    private const SCAN_LIMIT = 200;

    /**
     * The keys of the `schedule` JSON blob that may carry an ad's expiry, in the
     * order they are trusted (see resolveExpiry() for the reasoning).
     */
    private const EXPIRY_KEYS = [
        'expires_at',
        'expiresAt',
        'end_at',
        'endAt',
        'expires',
        'until',
    ];

    /**
     * GET /api/ads/open
     *
     * Authenticated, customer or space owner only. The route carries
     * `auth:sanctum`, so an anonymous caller is rejected with a 401 before it
     * reaches this method -- unlike the spaces endpoints, the ads feed is not
     * public data and must not be mounted outside that group.
     */
    public function open(Request $request)
    {
        $user = $request->user();

        // Re-checked here so the endpoint stays safe if it is ever mounted
        // outside the auth:sanctum group: without it a null user would fatal
        // instead of answering 401.
        if (!$user) {
            return response()->json([
                'message' => 'يجب تسجيل الدخول لعرض الإعلانات.',
            ], 401);
        }

        // Role checks live in the controller, not in a FormRequest: every
        // request object in this project returns authorize() => true.
        if (!in_array($user->role, self::ALLOWED_ROLES, true)) {
            return response()->json([
                'message' => 'لا تملك صلاحية الوصول إلى قائمة الإعلانات.',
            ], 403);
        }

        // `target` is NOT filtered on. The column carries the audience the owner
        // picked on the create form, but its vocabulary is not part of this
        // endpoint's contract, and filtering on it would silently hide ads whose
        // target happens to be anything other than 'customers'. The value is
        // passed through instead so the client can decide.

        // `space` is eager loaded only because the row exposes space_name; both
        // relations are eager loaded to keep this to three queries no matter how
        // many ads come back (no N+1).
        $ads = Ad::with(['owner', 'space'])
            // The single most important condition here: a draft has not been
            // approved by its owner yet and an archived one has been retired, so
            // neither may ever appear in a public feed.
            ->where('status', 'published')
            // Newest first, with the id as the tie-breaker so two ads created in
            // the same second cannot swap places between requests.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::SCAN_LIMIT)
            ->get();

        $now = Carbon::now();

        $formatted = [];

        foreach ($ads as $ad) {
            $expiresAt = $this->resolveExpiry($ad);

            // The second gate, after status: a published ad whose window has
            // closed is not served. A missing or unreadable expiry means the ad
            // runs until its owner archives it, which is the same contract the
            // frontend honours -- expires_at is optional there, and dropping
            // those ads here would empty the feed, since `schedule` is nullable
            // and most owners never fill it in.
            if ($expiresAt !== null && ! $expiresAt->gt($now)) {
                continue;
            }

            // The cap is enforced while the list is built rather than with a
            // take() at the end, so rows past the cap are never even formatted,
            // and the loop stops reading as soon as the feed is full.
            if (count($formatted) >= self::FEED_LIMIT) {
                break;
            }

            $formatted[] = $this->formatPublicAd($ad, $expiresAt);
        }

        return response()->json([
            // `data` is the primary key: it is what the API contract documents
            // for every list endpoint in this project.
            'data' => $formatted,
            // `ads` mirrors the same array. House style is to expose an ambiguous
            // key under both spellings the frontend may read, so a client built
            // against either one renders the strip instead of an empty list.
            'ads' => $formatted,
            'message' => 'تم جلب الإعلانات بنجاح.',
        ], 200);
    }

    /**
     * Projects an ad into the shape the banner strip reads.
     *
     * The expiry is passed in rather than re-derived: the caller already had to
     * read it to decide whether the ad is still live.
     */
    private function formatPublicAd(Ad $ad, ?Carbon $expiresAt): array
    {
        // User uses SoftDeletes, so `owner` is already null when the advertiser's
        // account was deleted; every owner field below has to be null safe.
        $owner = $ad->owner;

        $space = $ad->space;

        // Workspace imports SoftDeletes but never applies it, so a soft-deleted
        // workspace is still eager loaded. Its details must not leak into a
        // public feed, so it is dropped here rather than trusted blindly.
        if ($space !== null && $space->deleted_at !== null) {
            $space = null;
        }

        // sent_at is stamped by OwnerAdController::publish() at the moment the ad
        // went live, so it is the honest publication date. created_at is only the
        // row's creation time and can be weeks earlier -- a banner dated from it
        // would look stale the day it is published -- so it is the fallback for
        // a published row that predates the column.
        $publishedAt = $ad->sent_at ?? $ad->created_at;

        return [
            'id' => $ad->id,
            // Both spellings, per the house rule: the frontend reads one of them
            // and the other must never be missing.
            'ad_id' => $ad->id,
            'title' => $ad->title,
            'description' => $ad->description,
            'notes' => $ad->description,
            // Passed straight through, null included. The frontend already
            // sanitises this value (React Router for internal paths, SafeLink to
            // strip javascript: for external ones) and falls back to /spaces when
            // it is null. Substituting a default here would take that decision
            // away from the only layer that can make it safely.
            'link' => $ad->link,
            'url' => $ad->link,
            'image' => $ad->image,
            'target' => $ad->target,
            'space_id' => $ad->space_id,
            'space_name' => $space?->title,
            'spaceName' => $space?->title,
            'owner_name' => $owner?->full_name,
            'owner_avatar' => $owner?->profile_picture_url,
            // ISO-8601, the dominant convention for this API (ads, offers,
            // reviews, notifications and documents all use it) and the format the
            // frontend already parses for ads. A derived expiry keeps the offset
            // the owner supplied instead of being converted to the app timezone:
            // both spellings denote the same instant to the client's Date parser,
            // and echoing back what was stored is the least surprising thing to
            // do.
            'published_at' => $publishedAt?->toIso8601String(),
            'sent_at' => $ad->sent_at?->toIso8601String(),
            'created_at' => $ad->created_at?->toIso8601String(),
            // The derived expiry, ISO-8601, or null when the ad never expires.
            'expires_at' => $expiresAt?->toIso8601String(),
        ];
    }

    /**
     * Derives an ad's expiry from the `schedule` JSON blob.
     *
     * PRECEDENCE. `ads` has no `expires_at` column, and `schedule` is the only
     * place ad timing data already lives (it is what OwnerAdController validates
     * as `nullable|json` and persists). The keys are therefore consulted in this
     * fixed order, and the first one that yields a readable date wins:
     *
     *   1. expires_at  the spelling this endpoint's own contract uses, and the
     *                  one the frontend already filters on, so it is tried first.
     *   2. expiresAt   the camelCase twin a JavaScript client serialising an
     *                  object literal is most likely to have written.
     *   3. end_at /    the scheduling pair a windowed campaign is written as;
     *      endAt       `end_at` also mirrors `sent_at`, the column that marks
     *                  when an ad went live.
     *   4. expires /   a hand-authored schedule blob, where nobody followed the
     *      until      field naming convention of the code.
     *
     * Anything that is not a readable date -- absent key, null, blank string,
     * garbage -- is skipped rather than treated as "expired", and the remaining
     * keys still get their turn. When no key produces a date the ad is treated
     * as NON-expiring. That is the contract the frontend already implements: it
     * displays an ad whose expires_at is missing or unparseable, because the
     * field is optional there. Hiding those ads on the server would empty the
     * feed, since `schedule` is nullable and most owners never fill it in.
     */
    private function resolveExpiry(Ad $ad): ?Carbon
    {
        $schedule = $this->schedulePayload($ad);

        foreach (self::EXPIRY_KEYS as $key) {
            if (!array_key_exists($key, $schedule)) {
                continue;
            }

            $value = $schedule[$key];

            if ($value instanceof DateTimeInterface) {
                // Only reachable when a model was handed a Carbon in memory
                // rather than read back from the JSON column; instance() keeps
                // the instant and the offset intact.
                return Carbon::instance($value);
            }

            // A blank or non-string value carries no date. Note that an empty
            // string must be skipped rather than parsed: Carbon::parse('')
            // resolves to "now", which would expire the ad immediately.
            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            try {
                return Carbon::parse($value);
            } catch (Throwable $e) {
                // Unparseable in this spelling; try the next key. OwnerAdController
                // accepts whatever JSON the client sends, so a bad value must not
                // be able to take the whole feed down.
                continue;
            }
        }

        return null;
    }

    /**
     * Normalises the `schedule` column into an array.
     *
     * The Ad model casts the column with `json`, so a row written as a real JSON
     * object reads back as an array. But OwnerAdController validates `schedule`
     * with the `json` RULE, which only accepts a *string* -- so the value it
     * passes to Ad::create() is an already-encoded JSON string, which the cast
     * encodes a second time and the column stores as a JSON string scalar rather
     * than an object. Reading that back yields the encoded string, so it is
     * decoded once here and both shapes resolve to the same array.
     */
    private function schedulePayload(Ad $ad): array
    {
        $schedule = $ad->schedule;

        if (is_string($schedule)) {
            $decoded = json_decode($schedule, true);
            $schedule = is_array($decoded) ? $decoded : [];
        }

        // A scalar or null (no schedule at all) carries no keys to read.
        return is_array($schedule) ? $schedule : [];
    }
}