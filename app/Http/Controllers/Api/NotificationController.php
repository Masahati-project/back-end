<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $query = $user->notifications()->orderByDesc('created_at');

        if ($request->filled('after_id')) {
            $query->where('id', '>', $request->integer('after_id'));
        }

        if ($request->boolean('unread')) {
            $query->where(function ($q) {
                $q->where('is_read', false)->orWhereNull('is_read');
            });
        }

        $perPage = min($request->integer('per_page') ?: 20, 100);
        $notifications = $query->paginate($perPage);

        $formatted = $notifications->getCollection()->map(function ($n) {
            $read = (bool) ($n->is_read ?? $n->read ?? false);
            $body = $n->body ?? $n->message;
            $title = $n->title;
            $createdAt = $n->created_at?->toIso8601String();

            return [
                'id' => $n->id,
                'notification_id' => $n->id,
                'title' => $title,
                'subject' => $title,
                'heading' => $title,
                'body' => $body,
                'message' => $body,
                'text' => $body,
                'content' => $body,
                'description' => $body,
                'type' => $n->type ?? 'notification',
                'link' => $n->link,
                'url' => $n->link,
                'action_url' => $n->link,
                'icon' => $n->type ?? null,
                'read' => $read,
                'is_read' => $read,
                'seen' => $read,
                'read_at' => $n->read_at?->toIso8601String(),
                'created_at' => $createdAt,
                'sent_at' => $createdAt,
                'time' => $createdAt,
                'date' => $createdAt,
            ];
        })->values();

        return response()->json([
            'data' => [
                'notifications' => $formatted,
            ],
            'notifications' => $formatted,
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'total' => $notifications->total(),
                'per_page' => $notifications->perPage(),
            ],
            'message' => 'تم جلب الإشعارات بنجاح',
        ], 200);
    }

    public function unreadCount(Request $request)
    {
        $count = $request->user()->notifications()
            ->where(function ($q) {
                $q->where('is_read', false)->orWhereNull('is_read');
            })
            ->count();

        return response()->json([
            'data' => [
                'unread_count' => $count,
            ],
        ], 200);
    }

    public function markAsRead(Request $request, $id)
    {
        $notification = $request->user()->notifications()->find($id);

        if (! $notification) {
            return response()->json(['message' => 'الإشعار غير موجود.'], 404);
        }

        $notification->forceFill([
            'is_read' => true,
            'read' => true,
            'read_at' => $notification->read_at ?? now(),
        ])->save();

        return response()->json([
            'message' => 'تم تعليم الإشعار كمقروء.',
            'data' => [
                'id' => $notification->id,
                'read' => true,
                'read_at' => $notification->read_at?->toIso8601String(),
            ],
        ], 200);
    }

    public function markAllAsRead(Request $request)
    {
        $request->user()->notifications()
            ->where(function ($q) {
                $q->where('is_read', false)->orWhereNull('is_read');
            })
            ->update([
                'is_read' => true,
                'read' => true,
                'read_at' => now(),
            ]);

        return response()->json([
            'message' => 'تم تحديث الإشعارات.',
        ], 200);
    }
}
