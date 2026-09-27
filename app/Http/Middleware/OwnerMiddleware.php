<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OwnerMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() || $request->user()->role !== 'space_owner') {
            return response()->json([
                'message' => 'غير مصرح لك بالوصول لخدمات المالك.'
            ], 403);
        }

        return $next($request);
    }
}