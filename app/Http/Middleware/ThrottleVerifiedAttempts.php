<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Counts an attempt only once the request's identifier field is well formed.
 *
 * Laravel's stock ThrottleRequests runs *before* validation, so on this API a
 * request with an empty body still burned one of the user's five attempts on
 * POST /api/verify-otp. The frontend puts a 30 second cooldown on "resend OTP",
 * which means the most ordinary thing a user does tripped the limit on the first
 * probe — the "429 on first probe" the audit found on /verify-otp,
 * /resend-otp and /reset-password.
 *
 * This middleware instead runs one minimal, non-destructive validation of the
 * single field the rate-limit key is built from:
 *
 *   - Field missing, or not a usable string: the request is handed on untouched
 *     and consumes no quota. The controller still answers with its normal 422,
 *     so the caller sees a validation error rather than a lockout. This is the
 *     requirement "لا تُحتسب المحاولة إلا بعد نجاح التحقق من صحة الحقول الأساسية".
 *   - Field usable: RateLimiter::attempt() is called with the same key the named
 *     limiter in App\Providers\AppServiceProvider builds, and exceeding the limit
 *     throws a ThrottleRequestsException carrying Retry-After and X-RateLimit-*
 *     headers of its own — so the header guarantee in bootstrap/app.php holds
 *     whether or not that render callback ever runs.
 *
 * It deliberately does NOT extend Illuminate\Routing\Middleware\ThrottleRequests.
 * That class leans on protected members whose signatures have shifted across
 * Laravel versions, and this project tracks a floating laravel/framework ^12.0;
 * a self-contained class we own end to end is the safer trade.
 *
 * Registered in bootstrap/app.php under the `throttle-verified` alias and applied
 * in routes/api.php as `throttle-verified:verify-otp`,
 * `throttle-verified:resend-otp` and `throttle-verified:reset-password`.
 *
 * PII note: the identifier is a phone number's worth of personal data (an email
 * address, a registration token tied to one). Nothing here logs it, and no
 * identifier is ever written to an exception message or a header — it only ever
 * reaches the cache backend as part of a key. Keep it that way; a "helpful"
 * Log::info() of the key would put every user's email and phone number into
 * storage/logs.
 */
class ThrottleVerifiedAttempts
{
    /**
     * Named limiter for POST /api/verify-otp.
     *
     * The only identity this endpoint carries is `registration_token`: the UUID
     * handed back by /api/register/customer or /api/register/space-owner and
     * cached under "pending_registration_{token}" for 10 minutes. The frontend
     * never sends phone or email here, so keying on a phone number (as the
     * requirements doc suggested) would have meant keying on a field that is
     * simply absent, i.e. silently falling back to IP-only. See OtpController.
     */
    public const VERIFY_OTP = 'verify-otp';

    /**
     * Named limiter for POST /api/resend-otp. Same body shape as verify-otp.
     */
    public const RESEND_OTP = 'resend-otp';

    /**
     * Named limiter for POST /api/reset-password.
     *
     * `email` is the identity here, and it is also the target: this route
     * accepts a password-reset token, so the quota has to follow the account
     * being attacked rather than the machine sending the request.
     */
    public const RESET_PASSWORD = 'reset-password';

    /**
     * The single request field that says *who* is attempting, per named limiter.
     *
     * This map is the one place that answers "what identifies the caller here",
     * and it is deliberately shared: App\Providers\AppServiceProvider builds the
     * cache key from it and this middleware builds both its key and its
     * pre-validation from it. Two copies would drift, and a drifted copy is a
     * silently bypassable rate limit.
     */
    public const IDENTIFIER_FIELDS = [
        self::VERIFY_OTP => 'registration_token',
        self::RESEND_OTP => 'registration_token',
        self::RESET_PASSWORD => 'email',
    ];

    /**
     * Placeholder that collapses a request with no usable identifier onto the
     * IP-only bucket. Chosen as the literal string "ip" so the resulting key
     * reads as the dimension it actually is.
     */
    public const IP_ONLY = 'ip';

    /**
     * Longest identifier accepted and put in the cache key.
     *
     * Two reasons, one limit. The repo's own string fields cap at 255
     * (AuthController), but Redis and memcached reject long *keys* well below
     * that, and an unbounded identifier would also push an entire request body
     * into the cache backend. 100 leaves room for the limiter name, the IP and
     * the separators inside any real backend's key limit.
     */
    private const MAX_IDENTIFIER_LENGTH = 100;

    /**
     * Build the cache key for a limiter: "{limiter}:{identifier}:{ip}".
     *
     * The limiter name leads the key on purpose. Illuminate\Cache\RateLimiter
     * uses the string handed to attempt() as the *entire* cache key — it has no
     * per-limiter namespace of its own — so two named limiters that build the
     * same string share one counter. verify-otp and resend-otp read the same
     * `registration_token` from the same IP, so without the limiter name in the
     * key, exhausting verify-otp's five attempts would also lock the user out of
     * resend-otp. Prefixing with the limiter name makes route independence
     * structural instead of accidental.
     *
     * The IP is appended and never omitted, for the flood case: an unauthenticated
     * caller can invent unlimited registration tokens, and without an IP
     * component every one of them would mint a brand-new bucket that nothing
     * bounds.
     */
    public static function cacheKey(Request $request, string $limiter): string
    {
        $field = self::IDENTIFIER_FIELDS[$limiter] ?? null;

        $identifier = $field === null
            ? self::IP_ONLY
            : self::normaliseIdentifier($request->input($field));

        return $limiter.':'.$identifier.':'.$request->ip();
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $limiter = ''): Response
    {
        $field = self::IDENTIFIER_FIELDS[$limiter] ?? null;

        if ($field === null) {
            // Wiring mistake, not a client error. Throwing here is deliberate:
            // silently passing every request through would be an unthrottled
            // endpoint, and this fails loudly the first time it is exercised.
            throw new \InvalidArgumentException(
                "Rate limiter [{$limiter}] has no identifier field configured on "
                .self::class.'::IDENTIFIER_FIELDS.'
            );
        }

        // Only the identifier field is checked here. The controllers keep their
        // own full rule sets; duplicating them in a middleware would give the
        // same validation two homes that drift apart, and the requirement is
        // specifically that the *identifier* must be usable before the attempt
        // counts.
        if (! $this->identifierIsUsable($request, $field)) {
            // Untouched, unthrottled, no quota spent. The controller produces the
            // 422 the caller expects.
            return $next($request);
        }

        $limit = $this->limitFor($limiter, $request);
        $maxAttempts = $limit->maxAttempts();

        if ($maxAttempts === PHP_INT_MAX) {
            // Limit::none() — the limiter deliberately allows everything. Counting
            // it here would turn "no limit" into an accidental lockout.
            return $next($request);
        }

        $decaySeconds = $limit->decaySeconds();
        $key = self::cacheKey($request, $limiter);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            throw $this->throttleException($key, $maxAttempts, $decaySeconds);
        }

        // attempt() runs the callback only while under the limit, mirroring what
        // ThrottleRequests does internally.
        $response = RateLimiter::attempt($key, $maxAttempts, fn () => $next($request), $decaySeconds);

        if ($response === false) {
            // Unreachable: the tooManyAttempts() call above already rejected this
            // key. Kept so the declared Response return type is always honoured
            // without running the pipeline a second time.
            throw $this->throttleException($key, $maxAttempts, $decaySeconds);
        }

        return $response;
    }

    /**
     * Resolve the named limiter's Limit for this request.
     *
     * RateLimiter::limiter() throws InvalidArgumentException for a name that was
     * never registered, which is the right failure mode: a route pointing at a
     * limiter name that does not exist should break loudly rather than be
     * quietly unthrottled.
     */
    protected function limitFor(string $limiter, Request $request): Limit
    {
        $limit = RateLimiter::limiter($limiter)($request);

        if (! $limit instanceof Limit) {
            throw new \LogicException("Rate limiter [{$limiter}] must return a Limit instance.");
        }

        return $limit;
    }

    /**
     * The 429, built with its own headers so the guarantee does not depend on
     * bootstrap/app.php's render callback running.
     */
    protected function throttleException(string $key, int $maxAttempts, int $decaySeconds): ThrottleRequestsException
    {
        // availableIn() is the number of seconds left before this bucket refills,
        // which is the only accurate figure at this point. The configured decay
        // window is the fallback for the corner case where the key expired
        // between tooManyAttempts() and now.
        $retryAfter = RateLimiter::availableIn($key) ?: $decaySeconds;

        return new ThrottleRequestsException('Too Many Attempts.', null, [
            'X-RateLimit-Limit' => $maxAttempts,
            'X-RateLimit-Remaining' => 0,
            'Retry-After' => $retryAfter,
            // Non-standard, and kept for parity with Laravel's own throttle
            // headers: the absolute unix timestamp of the moment the bucket
            // refills. Retry-After above is the standard header and the one the
            // frontend reads.
            'X-RateLimit-Reset' => now()->addSeconds($retryAfter)->getTimestamp(),
        ]);
    }

    /**
     * Minimal, non-destructive check of the identifier field.
     *
     * Nothing on the request is merged, flagged or unset, so the controller's
     * own validation still sees exactly what the client sent.
     */
    protected function identifierIsUsable(Request $request, string $field): bool
    {
        // Validating the single key instead of $request->all() keeps this to one
        // rule with no database lookups and no possibility of colliding with the
        // controller's rule set.
        return ! Validator::make(
            [$field => $request->input($field)],
            [$field => 'required|string|max:'.self::MAX_IDENTIFIER_LENGTH]
        )->fails();
    }

    /**
     * Reduce an identifier to a stable bucket name.
     *
     * Normalisation is a security control, not cosmetics: without it, "0599…",
     * " 0599… " and "0599…" would be three separate buckets and the quota
     * would be bypassable by varying whitespace and case alone.
     */
    private static function normaliseIdentifier(mixed $value): string
    {
        // Only a string can identify anybody. Casting an array to a string is a
        // PHP warning, and Laravel converts warnings to ErrorExceptions, so a
        // body like {"email": {"a": 1}} would have turned into a 500 instead of
        // a 422 — the exact failure a peer agent shipped in the spaces filters.
        // is_string() is the guard.
        if (! is_string($value)) {
            return self::IP_ONLY;
        }

        // trim() for the whitespace padding; mb_strtolower() because the domain
        // part of an email is case-insensitive and Str::uuid() emits lowercase,
        // so one spelling means one bucket for both field types.
        $value = mb_strtolower(trim($value));

        if ($value === '') {
            return self::IP_ONLY;
        }

        if (mb_strlen($value) > self::MAX_IDENTIFIER_LENGTH) {
            // Shortening only — this is not anonymisation, because a 10 digit
            // phone number is trivially recoverable from a digest. Its value is
            // keeping the cache key within what Redis and memcached accept.
            return sha1($value);
        }

        return $value;
    }
}