<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A pending (not yet approved) or suspended account must not be able to do
 * anything on the platform: no bookings, no listings, no offers, no profile
 * edits. Login already refuses to issue a token for a non-active user
 * (AuthController::loginAccount, GoogleAuthController::loginWithGoogle), but a
 * token issued before an admin flipped the status stays valid until it is
 * revoked — so every authenticated route is re-checked here.
 *
 * Browsing is not "doing something". The spaces catalogue is public for
 * anonymous visitors (GET /api/spaces, /api/spaces/{id}), so a blocked account
 * keeps the read-only endpoints listed below. Without them a suspended user
 * with the app installed would hit a wall of 403s on the home screen instead
 * of simply not being able to act.
 *
 * Admins are exempt: locking an admin out through the same status flag would
 * leave no one able to un-suspend the account.
 */
class EnsureAccountIsActive
{
    /**
     * Read-only endpoints a blocked account keeps. These are catalogue and
     * inbox reads; nothing here creates, changes or removes anything.
     *
     * @var list<string>
     */
    private const READ_ONLY_ROUTES = [
        'user.logout',
        'profile',
        'spaces',
        'spaces.public.index',
        'spaces.public.show',
        'notifications.index',
        'notifications.unread-count',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->role === 'admin') {
            return $next($request);
        }

        $status = $user->status ?? 'pending';

        if ($status === 'active') {
            return $next($request);
        }

        // Logging out stays possible so a blocked user can still end the
        // session instead of being stuck with a dead token.
        if ($request->routeIs(...self::READ_ONLY_ROUTES)) {
            return $next($request);
        }

        return response()->json([
            'message' => $status === 'suspended'
                ? 'تم تعليق حسابك، لا يمكنك استخدام التطبيق حتى إعادة تفعيل الحساب'
                : 'حسابك قيد المراجعة، لا يمكنك استخدام التطبيق حتى تتم الموافقة عليه',
            'status' => $status,
        ], 403);
    }
}
