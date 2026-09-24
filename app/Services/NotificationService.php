<?php

namespace App\Services;

use App\Models\Notification;

class NotificationService
{
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }

    public static function create(int $userId, string $type, string $message): Notification
    {
        return Notification::create([
            'user_id' => $userId,
            'type' => $type,
            'message' => $message,
            'is_read' => false,
        ]);
    }

    public static function createForSpecialRequest(int $userId, string $action, string $requestTitle): Notification
    {
        $message = [
            'created' => "تم إنشاء طلب خاص جديد: {$requestTitle}",
            'accepted' => "تم قبول طلبك الخاص: {$requestTitle}",
            'rejected' => "تم رفض طلبك الخاص: {$requestTitle}",
            'closed' => "تم إغلاق طلبك الخاص: {$requestTitle}",
        ];
        return self::create($userId, 'special_request', $message[$action] ?? 'تحديث طلب خاص');
    }

    public static function createForOffer(int $userId, string $action, string $workspaceName): Notification
    {
        $message = [
            'received' => "تلقيت عرض جديد من {$workspaceName}",
            'accepted' => "تم قبول عرضك من {$workspaceName}",
            'rejected' => "تم رفض عرضك من {$workspaceName}",
        ];
        return self::create($userId, 'offer', $message[$action] ?? 'تحديث عرض');
    }
}
