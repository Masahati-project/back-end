<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two listings per owner per minute on POST /api/owner/spaces.
 *
 * The rule itself is the product's: an owner may add at most two spaces a
 * minute. What this class decides is *when* an attempt is counted, and the
 * answer is deliberately not "every request that arrives".
 *
 * A create call is a multipart upload carrying a required document, so getting
 * it right takes more than one attempt now and then. Counting every arrival
 * meant a user who fixed a typo twice in a minute was locked out of their own
 * form with "you have exceeded the number of attempts" — the same "429 on first
 * probe" that App\Http\Middleware\ThrottleVerifiedAttempts exists to prevent on
 * the auth endpoints, and the same reason that middleware validates one field
 * before spending an attempt.
 *
 * So the counter moves only when a listing is actually created:
 *   - Over the limit on arrival  -> 429, with Retry-After, and nothing runs.
 *   - A 422 from validation      -> no space exists, no quota spent. The user
 *                                   sees their validation errors and may correct
 *                                   and resubmit.
 *   - A 201                     -> one space written, one attempt counted.
 *
 * Nothing expensive is reachable without spending quota: validation runs before
 * the upload, so a rejected request never writes a file or a row.
 *
 * Deliberately NOT extending ThrottleRequests, for the reason that class gives
 * for itself — it leans on protected members whose signatures shift between
 * Laravel versions, and this project tracks a floating laravel/framework ^12.0.
 *
 * Registered in bootstrap/app.php under the `throttle-space-creation` alias and
 * applied in routes/api.php to POST /owner/spaces only. Updating a listing is
 * not rate limited: the rule is about how fast a space can be *added*, and a
 * listing's status does not change when its owner edits it.
 *
 * PII note: the key holds a user id, never a phone number, email or IP address,
 * except in the fallback below. As in ThrottleVerifiedAttempts, nothing here
 * logs the key and it never reaches an exception message.
 */
class ThrottleSpaceCreation
{
    /**
     * Named limiter, registered in AppServiceProvider. Resolved through
     * RateLimiter::limiter() rather than hardcoded here, so the number is
     * changed in one place.
     */
    public const LIMITER = 'owner-spaces';

    /**
     * Listings one owner may create per minute. The product rule.
     */
    public const MAX_PER_MINUTE = 2;

    /**
     * Only a 201 means a listing exists.
     *
     * Named rather than inlined as `$response->isSuccessful()`: a 204 or a 200
     * from some future refactor would silently start counting as a creation,
     * which is not a failure anyone would notice until an owner got locked out.
     */
    private const CREATED = 201;

    public function handle(Request $request, Closure $next): Response
    {
        $limit = $this->limitFor($request);
        $maxAttempts = $limit->maxAttempts;
        $key = $this->bucketKey($limit, $request);

        if ($maxAttempts !== PHP_INT_MAX && RateLimiter::remaining($key, $maxAttempts) <= 0) {
            throw $this->throttleException($key, $maxAttempts, $limit->decaySeconds);
        }

        $response = $next($request);

        if ($response->getStatusCode() === self::CREATED) {
            RateLimiter::hit($key, $limit->decaySeconds);
        }

        $response->headers->set('X-RateLimit-Limit', (string) $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', (string) RateLimiter::remaining($key, $maxAttempts));
        $response->headers->set('X-RateLimit-Reset', (string) now()->addSeconds($limit->decaySeconds)->getTimestamp());

        return $response;
    }

    /**
     * Resolve the named limiter's Limit for this request.
     *
     * RateLimiter::limiter() throws for a name that was never registered, which
     * is the right failure mode here: a route pointing at a limiter name that
     * does not exist should break loudly rather than be quietly unthrottled.
     */
    protected function limitFor(Request $request): Limit
    {
        $limit = RateLimiter::limiter(self::LIMITER)($request);

        if (! $limit instanceof Limit) {
            throw new \LogicException('Rate limiter ['.self::LIMITER.'] must return a Limit instance.');
        }

        return $limit;
    }

    /**
     * The cache key: "{limiter}:{the Limit's own key}".
     *
     * Illuminate\Cache\RateLimiter uses the string handed to it as the entire
     * cache key — it has no per-limiter namespace of its own — so the limiter
     * name leads, exactly as in ThrottleVerifiedAttempts::cacheKey(). Without
     * it, exhausting this bucket would also spend the quota of any other limiter
     * that happened to build the same identity string.
     *
     * The identity itself is the authenticated account, set on the Limit by
     * AppServiceProvider. Keying on the account rather than the address is
     * deliberate and goes both ways: one office behind a single NAT, or one
     * carrier CGNAT range, does not lock its users out of each other's quota —
     * and unlike an IP-keyed limit, an owner cannot walk around their own limit
     * by switching networks, which on mobile happens by itself.
     */
    private function bucketKey(Limit $limit, Request $request): string
    {
        $identity = filled($limit->key)
            ? $limit->key
            : 'ip:'.($request->user()?->getAuthIdentifier() ?? $request->ip());

        return self::LIMITER.':'.$identity;
    }

    /**
     * The 429, built with its own headers so the guarantee does not depend on
     * bootstrap/app.php's render callback running. Same shape as
     * ThrottleVerifiedAttempts::throttleException().
     */
    protected function throttleException(string $key, int $maxAttempts, int $decaySeconds): ThrottleRequestsException
    {
        $retryAfter = RateLimiter::availableIn($key) ?: $decaySeconds;

        return new ThrottleRequestsException('Too Many Attempts.', null, [
            'X-RateLimit-Limit' => $maxAttempts,
            'X-RateLimit-Remaining' => 0,
            'Retry-After' => $retryAfter,
            'X-RateLimit-Reset' => now()->addSeconds($retryAfter)->getTimestamp(),
        ]);
    }
}