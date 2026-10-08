<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureAdmin::class,
            // Counts an attempt only once the body's identifier field validates,
            // so an empty or malformed request cannot lock a user out of their
            // own quota. Applied in routes/api.php as
            // `throttle-verified:verify-otp`, `:resend-otp` and `:reset-password`;
            // the names are the ones registered in AppServiceProvider::boot().
            'throttle-verified' => \App\Http\Middleware\ThrottleVerifiedAttempts::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The frontend has a dedicated 429 branch that reads the Retry-After
        // header and shows the Arabic "you have exceeded the number of attempts,
        // please wait a moment" copy instead of the generic server-error message,
        // so that one header is what makes the difference. Laravel's
        // ThrottleRequests puts it in the exception's headers and
        // App\Http\Middleware\ThrottleVerifiedAttempts does the same, but this
        // callback is the last chance for *any* thrower of
        // ThrottleRequestsException, so it guarantees the header rather than
        // trusting it.
        $exceptions->render(function (ThrottleRequestsException $e, $request) {
            if (!$request->expectsJson()) {
                return null;
            }

            // Forwarded untouched, so X-RateLimit-Limit, X-RateLimit-Remaining,
            // X-RateLimit-Reset and Retry-After all keep working exactly as they
            // do today.
            $headers = $e->getHeaders();

            // Scanned case-insensitively: each thrower picks its own header
            // casing and Symfony passes it through verbatim. A non-positive value
            // counts as absent — see the floor at the bottom.
            $retryAfter = null;

            foreach ($headers as $name => $value) {
                if (strtolower((string) $name) === 'retry-after' && (int) $value > 0) {
                    $retryAfter = (int) $value;

                    break;
                }
            }

            if ($retryAfter === null && isset($headers['X-RateLimit-Reset']) && is_numeric($headers['X-RateLimit-Reset'])) {
                // X-RateLimit-Reset is the absolute unix timestamp of the moment
                // the bucket refills, so what is left to wait is the delta.
                $retryAfter = max(0, (int) $headers['X-RateLimit-Reset'] - now()->getTimestamp());
            }

            if ($retryAfter === null) {
                // Last resort, and never an underestimate: 60 seconds is the decay
                // window of every named limiter registered in AppServiceProvider
                // and of every inline throttle:N,1 in routes/api.php.
                $retryAfter = 60;
            }

            if (! isset($headers['Retry-After'])) {
                // Floored at one second. Retry-After: 0 is legal but useless —
                // a client that reads "retry after zero seconds" retries
                // immediately and turns a rate limit into a hot loop.
                $headers['Retry-After'] = max(1, $retryAfter);
            }

            return response()->json([
                'message' => 'عدد المحاولات كبير، يرجى المحاولة بعد قليل',
            ], 429, $headers);
        });
    })->create();