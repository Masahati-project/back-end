<?php

namespace App\Listeners;

use App\Events\SpecialRequestCreated;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendSpecialRequestNotification
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(SpecialRequestCreated $event): void
    {
        $specialRequest = $event->specialRequest;

        // A new request in the market is relevant to every active space owner,
        // not just the customer who raised it.
        $ownerIds = User::where('role', 'space_owner')
            ->where('status', 'active')
            ->whereNotNull('verified_at')
            ->where('id', '!=', $specialRequest->user_id)
            ->pluck('id');

        foreach ($ownerIds as $ownerId) {
            NotificationService::createForSpecialRequest(
                $ownerId,
                'created',
                $specialRequest->title
            );
        }
    }
}
