<?php

namespace Tests\Feature;

use App\Http\Middleware\ThrottleVerifiedAttempts;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Rate limiting on the three unauthenticated auth endpoints.
 *
 * These tests are all about quota bookkeeping, so the first thing to establish
 * is that the limiter state actually survives between the HTTP calls in a test.
 *
 * The suite runs with CACHE_STORE=array (phpunit.xml), and the array store is a
 * single Illuminate\Cache\ArrayStore instance memoised in the container's
 * CacheManager. Laravel does NOT rebuild the application between the requests
 * made inside one test method — only between test methods — so limiter state is
 * *shared for the duration of a test and discarded at the start of the next one.
 * That is exactly the lifetime a rate-limit test needs, and
 * test_limiter_state_survives_between_requests_within_a_test asserts it
 * directly by reading the counter back. Without that assertion these tests would
 * silently stop proving anything if the cache driver ever changed: every
 * request would start from zero and no limiter would ever trip.
 *
 * Nothing here touches the network. Every endpoint used returns before it would
 * reach BrevoMailService — an unknown registration_token answers 400 before the
 * mail is sent, and PasswordBroker::reset() returns INVALID_TOKEN before it
 * notifies anybody — so no Http::fake() is required to keep the suite hermetic.
 */
class AuthRateLimitTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * A body OtpController::verifyOtp() accepts: both fields present and of the
     * right type, which is exactly what makes the attempt countable.
     */
    private function verifyOtpBody(string $registrationToken): array
    {
        return [
            'registration_token' => $registrationToken,
            'code' => '123456',
        ];
    }

    /**
     * A body OtpController::resendOtp() accepts.
     */
    private function resendOtpBody(string $registrationToken): array
    {
        return ['registration_token' => $registrationToken];
    }

    /**
     * A body PasswordController::resetPassword() accepts, including the password
     * regex it enforces. The token is deliberately wrong: that makes the call a
     * real, counted attempt that ends in a 400 instead of a 200.
     */
    private function resetPasswordBody(User $user, ?string $email = null, string $token = 'not-a-real-token'): array
    {
        return [
            'token' => $token,
            'email' => $email ?? $user->email,
            'password' => 'Passw0rd!',
            'password_confirmation' => 'Passw0rd!',
        ];
    }

    /**
     * The exact cache key the middleware builds for an identifier.
     *
     * The identifier field is read out of the production map rather than
     * hard-coded, so this helper cannot drift from the limiter definitions.
     *
     * Laravel's test HTTP client builds every request through Symfony's
     * Request::create(), whose default server array carries
     * 'REMOTE_ADDR' => '127.0.0.1'. This app configures no trusted proxies, so
     * Request::ip() — the last segment of the key — is that same value for every
     * request made below. If that ever stops being true, the assertions that read
     * the counter back fail loudly with a 0 instead of quietly passing.
     */
    private function limiterKey(string $limiter, string $identifier): string
    {
        $field = ThrottleVerifiedAttempts::IDENTIFIER_FIELDS[$limiter];

        $probe = Request::create('/api/'.str_replace('_', '-', $limiter), 'POST', [$field => $identifier]);

        return ThrottleVerifiedAttempts::cacheKey($probe, $limiter);
    }

    /**
     * Burn the whole verify-otp quota for one registration token: five
     * well-formed requests, then a sixth that must be the 429.
     */
    private function lockOutVerifyOtp(string $registrationToken): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/verify-otp', $this->verifyOtpBody($registrationToken))
                ->assertStatus(400);
        }

        $this->postJson('/api/verify-otp', $this->verifyOtpBody($registrationToken))
            ->assertStatus(429);
    }

    // -------------------------------------------------------------------------
    // The limiter is real — the canary every other test in this file leans on
    // -------------------------------------------------------------------------

    public function test_limiter_state_survives_between_requests_within_a_test(): void
    {
        // phpunit.xml pins CACHE_STORE=array. That is only usable for a
        // rate-limit test because the ArrayStore instance is shared by every
        // request in a test method and thrown away between test methods. This
        // reads the counter the middleware wrote, so a change of cache driver —
        // or a future test that bypasses the middleware — breaks here loudly
        // instead of leaving the rest of the file asserting against zero hits.
        $token = (string) Str::uuid();
        $key = $this->limiterKey(ThrottleVerifiedAttempts::VERIFY_OTP, $token);

        $this->assertSame(0, RateLimiter::attempts($key), 'A fresh key must start empty.');

        $this->postJson('/api/verify-otp', $this->verifyOtpBody($token))->assertStatus(400);
        $this->assertSame(1, RateLimiter::attempts($key));

        $this->postJson('/api/verify-otp', $this->verifyOtpBody($token))->assertStatus(400);
        $this->assertSame(2, RateLimiter::attempts($key));

        // A different identifier is a different bucket, so the counter above is
        // untouched — the property the IP-only throttle could not offer.
        $otherKey = $this->limiterKey(ThrottleVerifiedAttempts::VERIFY_OTP, (string) Str::uuid());

        $this->assertSame(0, RateLimiter::attempts($otherKey));
    }

    // -------------------------------------------------------------------------
    // Retry-After on every 429
    // -------------------------------------------------------------------------

    public function test_a_429_on_verify_otp_carries_a_parsable_retry_after_header(): void
    {
        // The headline of this workstream: the frontend's 429 branch reads
        // Retry-After and shows the dedicated Arabic "wait a moment" copy. A 429
        // without the header falls through to the generic server-error message.
        $token = (string) Str::uuid();

        $this->lockOutVerifyOtp($token);

        // Replayed once more so the assertions below read off the 429 itself.
        $response = $this->postJson('/api/verify-otp', $this->verifyOtpBody($token));

        $response->assertStatus(429)
            ->assertJsonPath('message', 'عدد المحاولات كبير، يرجى المحاولة بعد قليل');

        $this->assertTrue(
            $response->headers->has('Retry-After'),
            'A 429 must always carry a Retry-After header.'
        );

        // Delta-seconds, not an HTTP-date: that is what the frontend parses, and
        // what both throwers of ThrottleRequestsException in this app emit.
        $retryAfter = (string) $response->headers->get('Retry-After');

        $this->assertMatchesRegularExpression('/^\d+$/', $retryAfter, 'Retry-After must be delta-seconds.');
        $this->assertGreaterThanOrEqual(1, (int) $retryAfter, 'Retry-After: 0 would ask the client to retry immediately.');

        // The headers that were already there must survive the render callback.
        $response->assertHeader('X-RateLimit-Limit', 5)
            ->assertHeader('X-RateLimit-Remaining', 0);
    }

    public function test_a_429_on_resend_otp_carries_a_parsable_retry_after_header(): void
    {
        // Same guarantee on the endpoint the frontend's 30 second "resend OTP"
        // cooldown actually talks to. This one was answering 429 on the first
        // probe in the audit.
        $token = (string) Str::uuid();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->postJson('/api/resend-otp', $this->resendOtpBody($token))
                ->assertStatus(400);
        }

        $response = $this->postJson('/api/resend-otp', $this->resendOtpBody($token));

        $response->assertStatus(429)
            ->assertHeader('X-RateLimit-Limit', 3);

        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertMatchesRegularExpression('/^\d+$/', (string) $response->headers->get('Retry-After'));
    }

    public function test_a_429_on_reset_password_carries_a_parsable_retry_after_header(): void
    {
        $user = User::factory()->create(['role' => 'customer']);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/reset-password', $this->resetPasswordBody($user))
                ->assertStatus(400);
        }

        $response = $this->postJson('/api/reset-password', $this->resetPasswordBody($user));

        $response->assertStatus(429)
            ->assertHeader('X-RateLimit-Limit', 5);

        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertMatchesRegularExpression('/^\d+$/', (string) $response->headers->get('Retry-After'));
    }

    // -------------------------------------------------------------------------
    // An attempt is only counted once the basic fields validate
    // -------------------------------------------------------------------------

    public function test_an_empty_body_never_consumes_verify_otp_quota(): void
    {
        // "لا تُحتسب المحاولة إلا بعد نجاح التحقق من صحة الحقول الأساسية".
        // ThrottleRequests ran before validation, so each of these empty requests
        // used to spend one of the user's five attempts — which is how a first
        // probe could come back 429.
        for ($request = 1; $request <= 10; $request++) {
            $this->postJson('/api/verify-otp', [])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['registration_token', 'code']);
        }

        $token = (string) Str::uuid();

        // A well-formed request straight afterwards is still inside the quota:
        // 400 is the controller's answer for an unknown registration_token, and
        // 429 here would mean the ten empty bodies had been counted.
        $this->postJson('/api/verify-otp', $this->verifyOtpBody($token))
            ->assertStatus(400);

        // And the counter proves it: exactly one hit, from the one valid request.
        $this->assertSame(1, RateLimiter::attempts(
            $this->limiterKey(ThrottleVerifiedAttempts::VERIFY_OTP, $token)
        ));
    }

    public function test_an_empty_body_never_consumes_resend_otp_quota(): void
    {
        // The same guarantee on the resend endpoint, which is the one the
        // frontend's cooldown drives.
        for ($request = 1; $request <= 10; $request++) {
            $this->postJson('/api/resend-otp', [])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['registration_token']);
        }

        $token = (string) Str::uuid();

        $this->postJson('/api/resend-otp', $this->resendOtpBody($token))
            ->assertStatus(400);

        $this->assertSame(1, RateLimiter::attempts(
            $this->limiterKey(ThrottleVerifiedAttempts::RESEND_OTP, $token)
        ));
    }

    public function test_an_empty_body_never_consumes_reset_password_quota(): void
    {
        $user = User::factory()->create(['role' => 'customer']);

        for ($request = 1; $request <= 10; $request++) {
            $this->postJson('/api/reset-password', [])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['token', 'email', 'password']);
        }

        $this->postJson('/api/reset-password', $this->resetPasswordBody($user))
            ->assertStatus(400);

        $this->assertSame(1, RateLimiter::attempts(
            $this->limiterKey(ThrottleVerifiedAttempts::RESET_PASSWORD, $user->email)
        ));
    }

    public function test_a_blank_identifier_is_treated_as_missing_rather_than_counted(): void
    {
        // Whitespace-only padding must not become its own bucket: trimming is
        // what stops "  " and "" from being two quotas for one user.
        $this->postJson('/api/verify-otp', [
            'registration_token' => '   ',
            'code' => '123456',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['registration_token']);

        $token = (string) Str::uuid();

        $this->postJson('/api/verify-otp', $this->verifyOtpBody($token))->assertStatus(400);

        $this->assertSame(1, RateLimiter::attempts(
            $this->limiterKey(ThrottleVerifiedAttempts::VERIFY_OTP, $token)
        ));
    }

    public function test_an_array_valued_identifier_is_a_422_not_a_500(): void
    {
        // A peer agent shipped a filter that answered 500 because it (string)-cast
        // an array input — the cast raises a PHP warning, and Laravel turns
        // warnings into ErrorExceptions. Key derivation has to ignore anything
        // that is not a usable string rather than blowing up, because both of
        // these bodies are attacker-controlled.
        $this->postJson('/api/verify-otp', [
            'registration_token' => ['a', 'b'],
            'code' => '123456',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['registration_token']);

        $this->postJson('/api/reset-password', [
            'token' => 'not-a-real-token',
            'email' => ['not', 'an', 'email'],
            'password' => 'Passw0rd!',
            'password_confirmation' => 'Passw0rd!',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_an_unusable_identifier_falls_back_to_the_ip_only_bucket(): void
    {
        // The key builder's own fallback, separate from the middleware's
        // pass-through: an identifier that is not a usable string must collapse
        // onto the IP bucket, so a flood of junk bodies shares one budget
        // instead of minting an unbounded number of buckets.
        $ipOnlyKey = $this->limiterKey(ThrottleVerifiedAttempts::VERIFY_OTP, ThrottleVerifiedAttempts::IP_ONLY);

        $arrayValued = Request::create('/api/verify-otp', 'POST', ['registration_token' => ['a', 'b']]);

        $this->assertSame(
            $ipOnlyKey,
            ThrottleVerifiedAttempts::cacheKey($arrayValued, ThrottleVerifiedAttempts::VERIFY_OTP),
            'An array-valued identifier must fall back to the IP bucket.'
        );

        $blank = Request::create('/api/verify-otp', 'POST', ['registration_token' => '   ']);

        $this->assertSame(
            $ipOnlyKey,
            ThrottleVerifiedAttempts::cacheKey($blank, ThrottleVerifiedAttempts::VERIFY_OTP),
            'A whitespace-only identifier must fall back to the IP bucket.'
        );

        $this->assertStringStartsWith(ThrottleVerifiedAttempts::VERIFY_OTP.':'.ThrottleVerifiedAttempts::IP_ONLY.':', $ipOnlyKey);
    }

    // -------------------------------------------------------------------------
    // Per-dimension keying
    // -------------------------------------------------------------------------

    public function test_exhausting_one_registration_token_does_not_lock_out_another(): void
    {
        // This is the IP-only bypass the audit found. Both requests come from the
        // same address (127.0.0.1), so under the old throttle:5,1 the second
        // identifier would have been locked out by the first one's spam.
        $this->lockOutVerifyOtp((string) Str::uuid());

        $this->postJson('/api/verify-otp', $this->verifyOtpBody((string) Str::uuid()))
            ->assertStatus(400)
            ->assertJsonPath('message', 'انتهت صلاحية الجلسة، يرجى إعادة محاولة التسجيل من جديد');
    }

    public function test_locking_out_one_email_does_not_lock_out_another(): void
    {
        // Same property on reset-password, where the dimension is the account
        // being attacked rather than the caller.
        $victim = User::factory()->create(['role' => 'customer']);
        $bystander = User::factory()->create(['role' => 'customer']);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/reset-password', $this->resetPasswordBody($victim))
                ->assertStatus(400);
        }

        $this->postJson('/api/reset-password', $this->resetPasswordBody($victim))
            ->assertStatus(429);

        $this->postJson('/api/reset-password', $this->resetPasswordBody($bystander))
            ->assertStatus(400)
            ->assertJsonPath('message', 'الرمز غير صالح أو انتهت صلاحيته.');
    }

    public function test_the_registration_token_is_normalised_into_one_bucket(): void
    {
        // Normalisation is a security control, not cosmetics: without trimming,
        // "token" and " token " are two quotas and the limit is bypassed by
        // varying whitespace alone.
        $token = (string) Str::uuid();
        $padded = '  ' . strtoupper($token) . '  ';

        $this->assertSame(
            $this->limiterKey(ThrottleVerifiedAttempts::VERIFY_OTP, $token),
            $this->limiterKey(ThrottleVerifiedAttempts::VERIFY_OTP, $padded),
            'Case and surrounding whitespace must not mint a second bucket.',
        );

        // Behavioural half: fill the quota with the padded spelling, then send
        // the clean one and expect the limit to still hold.
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/verify-otp', $this->verifyOtpBody($padded))
                ->assertStatus(400);
        }

        $this->postJson('/api/verify-otp', $this->verifyOtpBody($token))
            ->assertStatus(429);
    }

    public function test_the_email_is_normalised_into_one_bucket(): void
    {
        $this->assertSame(
            $this->limiterKey(ThrottleVerifiedAttempts::RESET_PASSWORD, 'Someone@Example.com'),
            $this->limiterKey(ThrottleVerifiedAttempts::RESET_PASSWORD, '  someone@example.COM '),
            'Email case is not significant, so both spellings are one bucket.',
        );
    }

    public function test_normalising_the_identifier_does_not_mutate_the_request(): void
    {
        // The middleware must be non-destructive: it reads the field, it does not
        // rewrite it. Padding the email here makes that visible, because the
        // controller's own `exists:users,email` rule rejects the padded value —
        // proving the request reached the controller exactly as it was sent.
        $user = User::factory()->create(['role' => 'customer']);
        $padded = '  ' . $user->email . '  ';

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/reset-password', $this->resetPasswordBody($user, $padded))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['email']);
        }

        $this->postJson('/api/reset-password', $this->resetPasswordBody($user, $padded))
            ->assertStatus(429);
    }

    public function test_an_over_long_identifier_still_produces_a_bounded_cache_key(): void
    {
        // Redis and memcached reject long keys, so an unbounded identifier must
        // be shortened rather than handed to the cache backend whole.
        $request = Request::create('/api/verify-otp', 'POST', [
            'registration_token' => str_repeat('a', 4096),
        ]);

        $key = ThrottleVerifiedAttempts::cacheKey($request, ThrottleVerifiedAttempts::VERIFY_OTP);

        $this->assertLessThanOrEqual(150, strlen($key));
        $this->assertStringStartsWith('verify-otp:', $key);
    }

    // -------------------------------------------------------------------------
    // Documented limits are unchanged
    // -------------------------------------------------------------------------

    public function test_verify_otp_still_allows_five_attempts_before_locking_out(): void
    {
        // Guards against the limit being changed by accident: the documented
        // attempt count for /api/verify-otp was, and stays, 5 per minute.
        $token = (string) Str::uuid();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/verify-otp', $this->verifyOtpBody($token))
                ->assertStatus(400)
                ->assertJsonStructure(['message']);
        }

        $this->postJson('/api/verify-otp', $this->verifyOtpBody($token))
            ->assertStatus(429);
    }

    public function test_resend_otp_still_allows_three_attempts_before_locking_out(): void
    {
        // /api/resend-otp was, and stays, the tightest window in the file: 3 per
        // minute, because every attempt is a real send through Brevo.
        $token = (string) Str::uuid();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->postJson('/api/resend-otp', $this->resendOtpBody($token))
                ->assertStatus(400);
        }

        $this->postJson('/api/resend-otp', $this->resendOtpBody($token))
            ->assertStatus(429);
    }

    public function test_reset_password_still_allows_five_attempts_before_locking_out(): void
    {
        $user = User::factory()->create(['role' => 'customer']);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/reset-password', $this->resetPasswordBody($user))
                ->assertStatus(400);
        }

        $this->postJson('/api/reset-password', $this->resetPasswordBody($user))
            ->assertStatus(429);
    }

    // -------------------------------------------------------------------------
    // The three routes are independent
    // -------------------------------------------------------------------------

    public function test_the_three_routes_are_throttled_independently(): void
    {
        // verify-otp and resend-otp read the same registration_token from the same
        // address, so an unnamespaced key would have made them share one counter
        // and locking one out would silently lock out the other.
        $token = (string) Str::uuid();
        $user = User::factory()->create(['role' => 'customer']);

        $this->lockOutVerifyOtp($token);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->postJson('/api/resend-otp', $this->resendOtpBody($token))
                ->assertStatus(400);
        }

        $this->postJson('/api/resend-otp', $this->resendOtpBody($token))
            ->assertStatus(429);

        // reset-password has its own bucket, so a request against it still gets a
        // real answer from its controller while both OTP routes are locked out.
        $this->postJson('/api/reset-password', $this->resetPasswordBody($user))
            ->assertStatus(400);
    }

    public function test_locking_out_reset_password_does_not_disturb_the_otp_routes(): void
    {
        // The same independence in the other direction: these three calls share
        // one IP with the locked-out account above, and none of them is affected.
        $user = User::factory()->create(['role' => 'customer']);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/reset-password', $this->resetPasswordBody($user))
                ->assertStatus(400);
        }

        $this->postJson('/api/reset-password', $this->resetPasswordBody($user))
            ->assertStatus(429);

        $this->postJson('/api/verify-otp', $this->verifyOtpBody((string) Str::uuid()))
            ->assertStatus(400);

        $this->postJson('/api/resend-otp', $this->resendOtpBody((string) Str::uuid()))
            ->assertStatus(400);
    }

    // -------------------------------------------------------------------------
    // The JSON render callback keeps its guard
    // -------------------------------------------------------------------------

    public function test_a_non_json_request_is_not_taken_over_by_the_json_renderer(): void
    {
        // The `if (!$request->expectsJson()) return null;` guard must stay: a
        // browser navigation to these URLs must not be handed the API's JSON
        // envelope. Symfony still forwards the exception's own headers on this
        // path, so Retry-After survives even though the body is not ours.
        $token = (string) Str::uuid();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/verify-otp', $this->verifyOtpBody($token))
                ->assertStatus(400);
        }

        // No Accept: application/json header on this one.
        $response = $this->post('/api/verify-otp', $this->verifyOtpBody($token));

        $response->assertStatus(429);

        $this->assertStringNotContainsString(
            'عدد المحاولات كبير',
            $response->getContent(),
            'A non-JSON request must not receive the API JSON envelope.'
        );

        $this->assertTrue(
            $response->headers->has('Retry-After'),
            'ThrottleRequestsException is an HttpExceptionInterface, so Symfony forwards its headers either way.'
        );
    }

    public function test_validation_errors_are_still_returned_by_the_controllers(): void
    {
        // The middleware hands a malformed body on untouched, so the controller's
        // own messages — not a lockout — are what the caller sees.
        $this->postJson('/api/verify-otp', [])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['registration_token', 'code']]);

        $this->postJson('/api/resend-otp', [])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['registration_token']]);
    }
}