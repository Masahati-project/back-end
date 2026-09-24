<?php

namespace App\Providers;

use App\Events\OfferAccepted;
use App\Events\OfferRejected;
use App\Events\SpecialRequestCreated;
use App\Listeners\SendOfferAcceptedNotification;
use App\Listeners\SendOfferRejectedNotification;
use App\Listeners\SendSpecialRequestNotification;
use App\Mail\BrevoTransport;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Mail;
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
    }
}
