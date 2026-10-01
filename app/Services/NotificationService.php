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
        // Types match the names the frontend filters on.
        $map = [
            'created'  => ['special_request_new',    "طلب جديد في السوق: «{$requestTitle}» — قدّم عرضك."],
            'accepted' => ['special_request_accepted', "تم قبول طلبك الخاص: {$requestTitle}"],
            'rejected' => ['special_request_closed',  "تم رفض طلبك الخاص: {$requestTitle}"],
            'closed'   => ['special_request_closed',  "تم إغلاق طلبك الخاص: {$requestTitle}"],
        ];

        [$type, $text] = $map[$action] ?? ['special_request', 'تحديث طلب خاص'];

        return self::create($userId, $type, $text);
    }

    public static function createForOffer(int $userId, string $action, string $workspaceName): Notification
    {
        $map = [
            'received' => ['special_request_offer',         "عرض جديد على طلبك «{$workspaceName}»"],
            'accepted' => ['special_request_offer_accepted', "تم قبول عرضك على «{$workspaceName}»"],
            'rejected' => ['special_request_offer_rejected', "تم رفض عرضك على «{$workspaceName}»"],
            'closed'   => ['special_request_offer_closed',   "أُغلق الطلب «{$workspaceName}» دون الرد على عرضك"],
        ];

        [$type, $text] = $map[$action] ?? ['special_request_offer', 'تحديث عرض'];

        return self::create($userId, $type, $text);
    }
}
