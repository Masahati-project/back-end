<?php

namespace App\Traits;

use App\Models\Ad;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Derives an ad's expiry from the `schedule` JSON blob — the single place in the
 * codebase that knows how ad timing is stored.
 *
 * WHY A SHARED TRAIT. `ads` has no `expires_at` column: the expiry of a campaign
 * only ever reaches the database inside `schedule`. Two endpoints need to make the
 * "is this ad still running?" decision — the public banner feed and the owner's own
 * "what is live right now" list — and a second implementation of that decision is a
 * guaranteed divergence: the day one of them learns a new spelling, the other keeps
 * serving finished campaigns. This trait was extracted from the copy that
 * PublicAdController::resolveExpiry() carried, byte for byte in behaviour.
 *
 * HOW TO ADOPT IT IN PublicAdController (left untouched here on purpose — another
 * workstream owns that file). Delete its private const EXPIRY_KEYS and its private
 * resolveExpiry() / schedulePayload() methods, add `use App\Traits\ResolvesAdExpiry;`
 * to the class body, and change the single call site in open() from
 * `$this->resolveExpiry($ad)` to `$this->resolveAdExpiry($ad)`. Nothing else in that
 * controller reads the schedule blob. Until that happens the two implementations
 * behave identically, so the duplication is inert rather than dangerous.
 *
 * Note that a class-defined method always wins over a trait one, so the adoption is
 * safe even if the private copies are left behind by mistake: no fatal, the class
 * simply keeps using its own.
 */
trait ResolvesAdExpiry
{
    /**
     * The keys of the `schedule` JSON blob that may carry an ad's expiry, in the
     * order they are trusted (see resolveAdExpiry() for the reasoning).
     */
    private const AD_EXPIRY_KEYS = [
        'expires_at',
        'expiresAt',
        'end_at',
        'endAt',
        'expires',
        'until',
    ];

    /**
     * Returns the instant an ad stops running, or null when it never expires.
     *
     * PRECEDENCE. `ads` has no `expires_at` column and `schedule` is the only place
     * ad timing data already lives (it is what OwnerAdController validates as
     * `nullable|json` and persists). The keys are therefore consulted in a fixed
     * order, and the first one that yields a readable date wins:
     *
     *   1. expires_at  the spelling the endpoints' own contract uses, and the one the
     *                  frontend already filters on, so it is tried first.
     *   2. expiresAt   the camelCase twin a JavaScript client serialising an object
     *                  literal is most likely to have written.
     *   3. end_at /    the scheduling pair a windowed campaign is written as; `end_at`
     *      endAt       also mirrors `sent_at`, the column that marks publication.
     *   4. expires /   a hand-authored schedule blob, where nobody followed the field
     *      until       naming convention of the code.
     *
     * Anything that is not a readable date — absent key, null, blank string, garbage
     * — is skipped rather than treated as "expired", and the remaining keys still get
     * their turn. When no key produces a date the ad is treated as NON-expiring: that
     * is the contract the frontend already implements (it displays an ad whose
     * expires_at is missing, because the field is optional there), and `schedule` is
     * nullable with most owners never filling it in, so treating "no expiry" as
     * "expired" would take the whole inventory off the air.
     */
    protected function resolveAdExpiry(Ad $ad): ?Carbon
    {
        $schedule = $this->adSchedulePayload($ad);

        foreach (self::AD_EXPIRY_KEYS as $key) {
            if (!array_key_exists($key, $schedule)) {
                continue;
            }

            $value = $schedule[$key];

            if ($value instanceof DateTimeInterface) {
                // Only reachable when a model was handed a Carbon in memory rather
                // than read back from the JSON column; instance() keeps the instant
                // and the offset intact.
                return Carbon::instance($value);
            }

            // A blank or non-string value carries no date. An empty string must be
            // skipped rather than parsed: Carbon::parse('') resolves to "now", which
            // would expire the ad immediately.
            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            try {
                return Carbon::parse($value);
            } catch (Throwable $e) {
                // Unparseable in this spelling; try the next key. OwnerAdController
                // accepts whatever JSON the client sends, so a bad value must not be
                // able to take an endpoint down.
                continue;
            }
        }

        return null;
    }

    /**
     * Whether an ad is still running at the given instant.
     *
     * Exposed separately because both the public feed and the owner's "open" list ask
     * exactly this question, and the answer must be the same answer.
     */
    protected function adIsRunning(Ad $ad, Carbon $now): bool
    {
        $expiresAt = $this->resolveAdExpiry($ad);

        return $expiresAt === null || $expiresAt->gt($now);
    }

    /**
     * Normalises the `schedule` column into an array.
     *
     * The Ad model casts the column with `json`, so a row written as a real JSON
     * object reads back as an array. But OwnerAdController validates `schedule` with
     * the `json` RULE, which only accepts a *string* — so the value it passes to
     * Ad::create() is an already-encoded JSON string, which the cast encodes a second
     * time and the column stores as a JSON string scalar rather than an object.
     * Reading that back yields the encoded string, so it is decoded once here and
     * both shapes resolve to the same array.
     */
    protected function adSchedulePayload(Ad $ad): array
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