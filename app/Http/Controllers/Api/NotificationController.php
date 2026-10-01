<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $notifications = $user->notifications()
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        // The frontend reads `text` and `read`; the columns are message and
        // is_read, so both spellings are exposed to avoid blank cells.
        $formatted = $notifications->getCollection()->map(fn ($n) => [
            'id' => $n->id,
            'type' => $n->type,
            'text' => $n->message,
            'message' => $n->message,
            'read' => (bool) $n->is_read,
            'is_read' => (bool) $n->is_read,
            'created_at' => $n->created_at?->toIso8601String(),
        ])->values();

        return response()->json([
            'data' => ['notifications' => $formatted],
            'notifications' => $formatted,
            'pagination' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'total' => $notifications->total(),
            ],
            'message' => 'تم جلب الإشعارات بنجاح',
        ], 200);
    }

    public function markAllAsRead(Request $request)
    {
        $user = $request->user();
        $user->notifications()->where('is_read', false)->update(['is_read' => true]);

        return response()->json([
            'message' => 'تم تحديث الإشعارات.',
        ], 200);
    }
}
