<?php

namespace App\Providers;

use App\Events\OfferAccepted;
use App\Events\OfferRejected;
use App\Events\SpecialRequestCreated;
use App\Http\Middleware\ThrottleVerifiedAttempts;
use App\Http\Middleware\ThrottleSpaceCreation;
use App\Listeners\SendOfferAcceptedNotification;
use App\Listeners\SendOfferRejectedNotification;
use App\Listeners\SendSpecialRequestNotification;
use App\Mail\BrevoTransport;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
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

        $this->registerAuthRateLimiters();
        $this->registerSpaceCreationRateLimiter();
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
        RateLimiter::for(ThrottleVerifiedAttempts::VERIFY_OTP, function (Request $request) {
            return Limit::perMinute(5)->by(
                ThrottleVerifiedAttempts::cacheKey($request, ThrottleVerifiedAttempts::VERIFY_OTP)
            );
        });

        RateLimiter::for(ThrottleVerifiedAttempts::RESEND_OTP, function (Request $request) {
            return Limit::perMinute(3)->by(
                ThrottleVerifiedAttempts::cacheKey($request, ThrottleVerifiedAttempts::RESEND_OTP)
            );
        });

        RateLimiter::for(ThrottleVerifiedAttempts::RESET_PASSWORD, function (Request $request) {
            return Limit::perMinute(5)->by(
                ThrottleVerifiedAttempts::cacheKey($request, ThrottleVerifiedAttempts::RESET_PASSWORD)
            );
        });
    }

    /**
     * POST /api/owner/spaces — two listings per owner per minute.
     *
     * Abuse stopped: a flood of listings to bury the admin's review queue. Every
     * listing is a pending row, a unit, a pricing row and a document upload, and
     * an owner account is now active from registration, so nothing upstream
     * stands between an owner and an arbitrarily long list of spaces.
     *
     * The identity is the authenticated owner, not the request IP. The three
     * limiters above are keyed on an unauthenticated field, where the IP has to
     * be in the key because that field is attacker-suppliable; here the caller
     * is already identified by their token, and an IP-keyed bucket would be both
     * wrong (one office, or one carrier CGNAT range, locking out every user
     * behind it) and useless (an owner switching networks resets their own limit).
     *
     * The attempt itself is counted by
     * App\Http\Middleware\ThrottleSpaceCreation, and only for a listing that was
     * really created.
     */
    protected function registerSpaceCreationRateLimiter(): void
    {
        RateLimiter::for(ThrottleSpaceCreation::LIMITER, function (Request $request) {
            $user = $request->user();

            return Limit::perMinute(ThrottleSpaceCreation::MAX_PER_MINUTE)->by(
                $user ? 'user:'.$user->getAuthIdentifier() : 'ip:'.$request->ip()
            );
        });
    }
}