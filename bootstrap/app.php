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
        $middleware->api(prepend: [
            \App\Http\Middleware\ForceJsonResponse::class,
        ]);
        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureAdmin::class,
            'throttle-verified' => \App\Http\Middleware\ThrottleVerifiedAttempts::class,
            'throttle-space-creation' => \App\Http\Middleware\ThrottleSpaceCreation::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ThrottleRequestsException $e, $request) {
            if (!$request->expectsJson()) {
                return null;
            }

            $headers = $e->getHeaders();

            $retryAfter = null;

            foreach ($headers as $name => $value) {
                if (strtolower((string) $name) === 'retry-after' && (int) $value > 0) {
                    $retryAfter = (int) $value;

                    break;
                }
            }

            if ($retryAfter === null && isset($headers['X-RateLimit-Reset']) && is_numeric($headers['X-RateLimit-Reset'])) {
                $retryAfter = max(0, (int) $headers['X-RateLimit-Reset'] - now()->getTimestamp());
            }

            if ($retryAfter === null) {
                $retryAfter = 60;
            }

            if (! isset($headers['Retry-After'])) {
                $headers['Retry-After'] = max(1, $retryAfter);
            }

            return response()->json([
                'message' => 'عدد المحاولات كبير، يرجى المحاولة بعد قليل',
            ], 429, $headers);
        });
    })->create();