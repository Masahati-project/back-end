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
        $middleware->alias(['admin' => \App\Http\Middleware\EnsureAdmin::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The frontend has no 429-specific handling, so it was rendering the
        // generic server-error copy. Retry-After lets it say "try again shortly".
        $exceptions->render(function (ThrottleRequestsException $e, $request) {
            if (!$request->expectsJson()) {
                return null;
            }

            return response()->json([
                'message' => 'عدد المحاولات كبير، يرجى المحاولة بعد قليل',
            ], 429, $e->getHeaders());
        });
    })->create();
