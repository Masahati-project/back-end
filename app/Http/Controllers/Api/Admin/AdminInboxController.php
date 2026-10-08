<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\InboxNotification;
use Illuminate\Http\Request;

class AdminInboxController extends Controller
{
    /**
     * List inbox notifications
     * GET /api/admin/inbox
     */
    public function index(Request $request)
    {
        $query = InboxNotification::query();

        $filter = $request->get('filter', 'all');
        if ($filter === 'unread') {
            $query->where('read', false);
        } elseif (in_array($filter, ['dispute', 'space_request', 'report'])) {
            $query->where('category', $filter);
        }

        $view = $request->get('view', 'inbox');
        $archived = $view === 'archived';
        $query->where('archived', $archived);

        $perPage = min($request->integer('per_page', 20), 100);
        $notifications = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'data' => $notifications->map(fn($n) => $this->formatNotification($n))->toArray(),
            'meta' => [
                'page' => $notifications->currentPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
                'last_page' => $notifications->lastPage(),
            ]
        ]);
    }

    /**
     * Get unread count
     * GET /api/admin/inbox/unread-count
     */
    public function unreadCount()
    {
        return response()->json([
            'data' => [
                'unread' => InboxNotification::where('read', false)->where('archived', false)->count(),
                'archived' => InboxNotification::where('archived', true)->count(),
            ]
        ]);
    }

    /**
     * Update notification
     * PATCH /api/admin/inbox/{id}
     */
    public function update(Request $request, $id)
    {
        $notification = InboxNotification::findOrFail($id);

        if ($request->has('read')) {
            $notification->read = $request->boolean('read');
        }
        if ($request->has('archived')) {
            $notification->archived = $request->boolean('archived');
        }

        $notification->save();

        return response()->json([
            'data' => [
                'id' => $notification->id,
                'read' => $notification->read,
                'archived' => $notification->archived,
            ]
        ]);
    }

    /**
     * Bulk operations
     * POST /api/admin/inbox/bulk
     */
    public function bulk(Request $request)
    {
        $request->validate([
            'ids' => 'required|array|min:1|max:500',
            'ids.*' => 'integer',
            'action' => 'required|in:read,unread,archive,unarchive,delete',
        ]);

        $ids = $request->ids;
        $action = $request->action;

        $notifications = InboxNotification::whereIn('id', $ids);

        match($action) {
            'read' => $notifications->update(['read' => true]),
            'unread' => $notifications->update(['read' => false]),
            'archive' => $notifications->update(['archived' => true]),
            'unarchive' => $notifications->update(['archived' => false]),
            'delete' => $notifications->delete(),
        };

        return response()->json([
            'data' => [
                'updated' => count($ids),
                'ids' => $ids,
            ]
        ]);
    }

    /**
     * Mark all as read
     * POST /api/admin/inbox/mark-all-read
     */
    public function markAllRead()
    {
        $count = InboxNotification::where('archived', false)->update(['read' => true]);

        return response()->json([
            'data' => [
                'updated' => $count,
            ]
        ]);
    }

    /**
     * Get categories
     * GET /api/admin/inbox/categories
     */
    public function categories()
    {
        return response()->json([
            'data' => [
                'dispute' => [
                    'label' => 'نزاع',
                    'tone' => 'red',
                    'cta' => 'عرض النزاع',
                    'path' => '/admin/bookings',
                ],
                'space_request' => [
                    'label' => 'طلب مساحة',
                    'tone' => 'blue',
                    'cta' => 'مراجعة المساحة',
                    'path' => '/admin/spaces',
                ],
                'report' => [
                    'label' => 'بلاغ',
                    'tone' => 'violet',
                    'cta' => 'مراجعة البلاغ',
                    'path' => '/admin/reviews',
                ],
            ]
        ]);
    }

    private function formatNotification($notification)
    {
        return [
            'id' => $notification->id,
            'category' => $notification->category,
            'title' => $notification->title,
            'body' => $notification->body,
            'time' => $this->formatArabicRelativeTime($notification->created_at),
            'read' => $notification->read,
            'archived' => $notification->archived,
            'created_at' => $notification->created_at->toIso8601String(),
            'ref' => $notification->ref,
        ];
    }

    private function formatArabicRelativeTime($datetime)
    {
        $diff = now()->diffInMinutes($datetime);
        if ($diff < 1) return 'الآن';
        if ($diff < 60) return "منذ $diff دقيقة";
        $hours = floor($diff / 60);
        if ($hours < 24) return "منذ $hours ساعات";
        $days = floor($hours / 24);
        if ($days == 1) return 'أمس';
        return $datetime->format('Y-m-d');
    }
}
