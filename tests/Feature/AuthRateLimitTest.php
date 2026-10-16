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

    public function test_limiter_state_survives_between_requests_within_a_test(): void
    {
        $token = (string) Str::uuid();
        $key = $this->limiterKey(ThrottleVerifiedAttempts::VERIFY_OTP, $token);

        $this->assertSame(0, RateLimiter::attempts($key), 'A fresh key must start empty.');

        $this->postJson('/api/verify-otp', $this->verifyOtpBody($token))->assertStatus(400);
        $this->assertSame(1, RateLimiter::attempts($key));

        $this->postJson('/api/verify-otp', $this->verifyOtpBody($token))->assertStatus(400);
        $this->assertSame(2, RateLimiter::attempts($key));

        $otherKey = $this->limiterKey(ThrottleVerifiedAttempts::VERIFY_OTP, (string) Str::uuid());

        $this->assertSame(0, RateLimiter::attempts($otherKey));
    }

    public function test_a_429_on_verify_otp_carries_a_parsable_retry_after_header(): void
    {
        $token = (string) Str::uuid();

        $this->lockOutVerifyOtp($token);

        $response = $this->postJson('/api/verify-otp', $this->verifyOtpBody($token));

        $response->assertStatus(429)
            ->assertJsonPath('message', 'عدد المحاولات كبير، يرجى المحاولة بعد قليل');

        $this->assertTrue(
            $response->headers->has('Retry-After'),
            'A 429 must always carry a Retry-After header.'
        );

        $retryAfter = (string) $response->headers->get('Retry-After');

        $this->assertMatchesRegularExpression('/^\d+$/', $retryAfter, 'Retry-After must be delta-seconds.');
        $this->assertGreaterThanOrEqual(1, (int) $retryAfter, 'Retry-After: 0 would ask the client to retry immediately.');

        $response->assertHeader('X-RateLimit-Limit', 5)
            ->assertHeader('X-RateLimit-Remaining', 0);
    }

    public function test_a_429_on_resend_otp_carries_a_parsable_retry_after_header(): void
    {
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
                ->assertStatus(422);
        }

        $response = $this->postJson('/api/reset-password', $this->resetPasswordBody($user));

        $response->assertStatus(429)
            ->assertHeader('X-RateLimit-Limit', 5);

        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertMatchesRegularExpression('/^\d+$/', (string) $response->headers->get('Retry-After'));
    }

    public function test_an_empty_body_never_consumes_verify_otp_quota(): void
    {
        for ($request = 1; $request <= 10; $request++) {
            $this->postJson('/api/verify-otp', [])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['registration_token', 'code']);
        }

        $token = (string) Str::uuid();

        $this->postJson('/api/verify-otp', $this->verifyOtpBody($token))
            ->assertStatus(400);

        $this->assertSame(1, RateLimiter::attempts(
            $this->limiterKey(ThrottleVerifiedAttempts::VERIFY_OTP, $token)
        ));
    }

    public function test_an_empty_body_never_consumes_resend_otp_quota(): void
    {
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
            ->assertStatus(422);

        $this->assertSame(1, RateLimiter::attempts(
            $this->limiterKey(ThrottleVerifiedAttempts::RESET_PASSWORD, $user->email)
        ));
    }

    public function test_a_blank_identifier_is_treated_as_missing_rather_than_counted(): void
    {
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

    public function test_exhausting_one_registration_token_does_not_lock_out_another(): void
    {
        $this->lockOutVerifyOtp((string) Str::uuid());

        $this->postJson('/api/verify-otp', $this->verifyOtpBody((string) Str::uuid()))
            ->assertStatus(400)
            ->assertJsonPath('message', 'انتهت صلاحية الجلسة، يرجى إعادة محاولة التسجيل من جديد');
    }

    public function test_locking_out_one_email_does_not_lock_out_another(): void
    {
        $victim = User::factory()->create(['role' => 'customer']);
        $bystander = User::factory()->create(['role' => 'customer']);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/reset-password', $this->resetPasswordBody($victim))
                ->assertStatus(422);
        }

        $this->postJson('/api/reset-password', $this->resetPasswordBody($victim))
            ->assertStatus(429);

        $this->postJson('/api/reset-password', $this->resetPasswordBody($bystander))
            ->assertStatus(422)
            ->assertJsonPath('message', 'الرمز غير صالح أو انتهت صلاحيته.');
    }

    public function test_the_registration_token_is_normalised_into_one_bucket(): void
    {
        $token = (string) Str::uuid();
        $padded = '  ' . strtoupper($token) . '  ';

        $this->assertSame(
            $this->limiterKey(ThrottleVerifiedAttempts::VERIFY_OTP, $token),
            $this->limiterKey(ThrottleVerifiedAttempts::VERIFY_OTP, $padded),
            'Case and surrounding whitespace must not mint a second bucket.',
        );

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
        $padded = '  SomeOne@Example.com  ';

        $request = Request::create('/api/reset-password', 'POST', ['email' => $padded]);

        $response = (new ThrottleVerifiedAttempts())->handle(
            $request,
            fn () => response('passed through'),
            ThrottleVerifiedAttempts::RESET_PASSWORD
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($padded, $request->input('email'), 'the middleware rewrote the request');
        $this->assertArrayHasKey('email', $request->all());

        $this->assertSame(
            'reset-password:someone@example.com:127.0.0.1',
            ThrottleVerifiedAttempts::cacheKey($request, ThrottleVerifiedAttempts::RESET_PASSWORD)
        );
    }

    public function test_an_over_long_identifier_still_produces_a_bounded_cache_key(): void
    {
        $request = Request::create('/api/verify-otp', 'POST', [
            'registration_token' => str_repeat('a', 4096),
        ]);

        $key = ThrottleVerifiedAttempts::cacheKey($request, ThrottleVerifiedAttempts::VERIFY_OTP);

        $this->assertLessThanOrEqual(150, strlen($key));
        $this->assertStringStartsWith('verify-otp:', $key);
    }

    public function test_verify_otp_still_allows_five_attempts_before_locking_out(): void
    {
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
                ->assertStatus(422);
        }

        $this->postJson('/api/reset-password', $this->resetPasswordBody($user))
            ->assertStatus(429);
    }

    public function test_the_three_routes_are_throttled_independently(): void
    {
        $token = (string) Str::uuid();
        $user = User::factory()->create(['role' => 'customer']);

        $this->lockOutVerifyOtp($token);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->postJson('/api/resend-otp', $this->resendOtpBody($token))
                ->assertStatus(400);
        }

        $this->postJson('/api/resend-otp', $this->resendOtpBody($token))
            ->assertStatus(429);

        $this->postJson('/api/reset-password', $this->resetPasswordBody($user))
            ->assertStatus(422);
    }

    public function test_locking_out_reset_password_does_not_disturb_the_otp_routes(): void
    {
        $user = User::factory()->create(['role' => 'customer']);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/reset-password', $this->resetPasswordBody($user))
                ->assertStatus(422);
        }

        $this->postJson('/api/reset-password', $this->resetPasswordBody($user))
            ->assertStatus(429);

        $this->postJson('/api/verify-otp', $this->verifyOtpBody((string) Str::uuid()))
            ->assertStatus(400);

        $this->postJson('/api/resend-otp', $this->resendOtpBody((string) Str::uuid()))
            ->assertStatus(400);
    }

    public function test_a_non_json_request_is_not_taken_over_by_the_json_renderer(): void
    {
        $token = (string) Str::uuid();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/verify-otp', $this->verifyOtpBody($token))
                ->assertStatus(400);
        }

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
        $this->postJson('/api/verify-otp', [])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['registration_token', 'code']]);

        $this->postJson('/api/resend-otp', [])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['registration_token']]);
    }
}
