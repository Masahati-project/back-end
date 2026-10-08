<?php

namespace App\Providers;

use App\Events\OfferAccepted;
use App\Events\OfferRejected;
use App\Events\SpecialRequestCreated;
<<<<<<< HEAD
use App\Http\Middleware\ThrottleVerifiedAttempts;
=======
>>>>>>> 70ab341a93cda185b5426b47c12600dcb3d90687
use App\Listeners\SendOfferAcceptedNotification;
use App\Listeners\SendOfferRejectedNotification;
use App\Listeners\SendSpecialRequestNotification;
use App\Mail\BrevoTransport;
use Illuminate\Auth\Notifications\ResetPassword;
<<<<<<< HEAD
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
=======
use Illuminate\Support\Facades\Mail;
>>>>>>> 70ab341a93cda185b5426b47c12600dcb3d90687
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    protected $listen = [
        SpecialRequestCreated::class => [SendSpecialRequestNotification::class],
        OfferAccepted::class => [SendOfferAcceptedNotification::class],
        OfferRejected::class => [SendOfferRejectedNotification::class],
    ];
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Mail::extend('brevo', function () {
            return new BrevoTransport();
        });

        ResetPassword::createUrlUsing(function($user, string $token) {
            return config('app.frontend_url') .
            '/reset-password?token=' . $token .
            '&email=' . urlencode($user->email);
        });
<<<<<<< HEAD

        $this->registerAuthRateLimiters();
    }

    /**
     * Named rate limiters for the three unauthenticated auth endpoints that the
     * audit found answering 429 on a first probe.
     *
     * Until now every route in routes/api.php used an inline `throttle:N,1`.
     * That buys an attempt count and nothing else: the key is the request IP, so
     * one person (or one NAT'd office, or one shared mobile carrier CGNAT range —
     * which in Gaza is most of the country) locking themselves out also locks
     * out every other user behind the same address. And because
     * ThrottleRequests runs before validation, an empty request body still spent
     * an attempt.
     *
     * Naming the limiters fixes both, and does it in one place:
     *   - routes/api.php applies them through the `throttle-verified` alias, e.g.
     *     ->middleware('throttle-verified:verify-otp');
     *   - App\Http\Middleware\ThrottleVerifiedAttempts resolves the same name to
     *     get the limits, refuses to count a request whose identifier field is
     *     not well formed, and throws the 429 with its own headers.
     *
     * The attempt counts and decay windows below are the ones that were already
     * in routes/api.php. Only the keying changed; the numbers are a product
     * decision, not an engineering one.
     */
    protected function registerAuthRateLimiters(): void
    {
        // POST /api/verify-otp — was throttle:5,1.
        //
        // Abuse stopped: brute-forcing the six digit code checked by
        // OtpController::verifyOtp(). Five guesses per registration token per
        // minute against a 1,000,000 value space leaves guessing hopeless.
        //
        // The identifier is `registration_token`, the UUID returned by
        // /api/register/customer and /api/register/space-owner and cached for ten
        // minutes under "pending_registration_{token}". It is the only identity
        // this endpoint carries — the frontend sends no phone or email here — and
        // it is a high entropy secret, so an attacker who does not hold it cannot
        // even name the bucket they are trying to fill.
        //
        // The IP stays in the key and that is not optional: registration tokens
        // are attacker-suppliable, so without it every random token would mint a
        // brand-new bucket that nothing bounds. (A flood of made-up tokens is
        // still capped upstream by the IP-only throttle:5,1 on /api/register/*,
        // which is the only place a token can come from.)
        RateLimiter::for(ThrottleVerifiedAttempts::VERIFY_OTP, function (Request $request) {
            return Limit::perMinute(5)->by(
                ThrottleVerifiedAttempts::cacheKey($request, ThrottleVerifiedAttempts::VERIFY_OTP)
            );
        });

        // POST /api/resend-otp — was throttle:3,1, deliberately the tightest
        // window in the file.
        //
        // Abuse stopped: burning the mail budget. Every resend is a real SMTP
        // send through BrevoMailService, and the OTP is replaced each time, so an
        // unbounded endpoint here is both a cost line and a way to invalidate a
        // code the user is currently typing. Keyed exactly like verify-otp but
        // kept in its own bucket — see ThrottleVerifiedAttempts::cacheKey() for
        // why two named limiters must not build the same key string.
        RateLimiter::for(ThrottleVerifiedAttempts::RESEND_OTP, function (Request $request) {
            return Limit::perMinute(3)->by(
                ThrottleVerifiedAttempts::cacheKey($request, ThrottleVerifiedAttempts::RESEND_OTP)
            );
        });

        // POST /api/reset-password — was throttle:5,1.
        //
        // Abuse stopped: guessing a password reset token, and probing which
        // addresses have accounts (the `exists:users,email` rule answers that
        // question). `email` is the identity and also the target, so the quota
        // follows the account being attacked rather than the machine sending the
        // request: five attempts against one victim from a shared address no
        // longer starves the rest of the building.
        RateLimiter::for(ThrottleVerifiedAttempts::RESET_PASSWORD, function (Request $request) {
            return Limit::perMinute(5)->by(
                ThrottleVerifiedAttempts::cacheKey($request, ThrottleVerifiedAttempts::RESET_PASSWORD)
            );
        });
=======
>>>>>>> 70ab341a93cda185b5426b47c12600dcb3d90687
    }
}
