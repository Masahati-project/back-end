<?php

namespace App\Listeners;

use App\Events\OfferRejected;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendOfferRejectedNotification
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
    public function handle(OfferRejected $event): void
    {
        $workspaceName = $event->specialRequest->workspace?->name ?? 'المساحة';
        NotificationService::createForOffer(
            $event->specialRequest->user_id,
            'rejected',
            $workspaceName
        );
    }
}
