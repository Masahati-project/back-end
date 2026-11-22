<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * POST /api/broadcasting/auth
 *
 * Pusher/Soketi-compatible channel authorization. `pusher-js` posts
 * `{socket_id, channel_name}` with the same Sanctum bearer token it uses for
 * `/api/*`; the response is the `{auth}` signature the socket server expects.
 *
 * Only the two private channels this app defines are allowed, and only for the
 * authenticated user's own id — there are no wildcards and no "allow all".
 */
class BroadcastingAuthController extends Controller
{
    public function authenticate(Request $request)
    {
        $validated = $request->validate([
            'socket_id' => ['required', 'string'],
            'channel_name' => ['required', 'string'],
        ]);

        $channel = $validated['channel_name'];

        if (! $this->userMayJoin($request->user(), $channel)) {
            return response()->json([
                'message' => 'غير مصرّح بالاشتراك في هذه القناة.',
            ], 403);
        }

        $key = config('broadcasting.connections.pusher.key');
        $secret = config('broadcasting.connections.pusher.secret');

        if (blank($key) || blank($secret)) {
            return response()->json([
                'message' => 'خدمة البث غير مهيأة على الخادم.',
            ], 503);
        }

        $signature = hash_hmac('sha256', $validated['socket_id'].':'.$channel, $secret);

        return response()->json([
            'auth' => $key.':'.$signature,
        ]);
    }

    private function userMayJoin(User $user, string $channel): bool
    {
        foreach (['private-owner', 'private-user'] as $prefix) {
            if (preg_match('/^'.preg_quote($prefix, '/').'\.(\d+)$/', $channel, $matches)) {
                return (int) $matches[1] === (int) $user->id;
            }
        }

        return false;
    }
}
