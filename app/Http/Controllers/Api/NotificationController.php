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
        $notifications = $user->notifications()->orderByDesc('created_at')->get();

        return response()->json([
            'notifications' => $notifications,
            'message' => 'تم جلب الإشعارات بنجاح',
        ], 200);
    }

    public function markAllAsRead(Request $request)
    {
        $user = $request->user();
        $user->notifications()->where('is_read', false)->update(['is_read' => true]);

        return response()->json([
            'message' => 'تم تعيين جميع الإشعارات كمقروءة',
        ], 200);
    }
}
