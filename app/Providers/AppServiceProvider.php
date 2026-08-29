<?php

namespace App\Providers;

use App\Mail\BrevoTransport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
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

        Password::createUrlUsing(function($user, string $token) {
            return config('app.frontend_url') .
            '/reset-password?token=' . $token .
            '&email=' . urlencode($user->email);
        });
    }
}
