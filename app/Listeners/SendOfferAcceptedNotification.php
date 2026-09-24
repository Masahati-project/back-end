<?php

namespace App\Listeners;

use App\Events\OfferAccepted;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendOfferAcceptedNotification
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
    public function handle(OfferAccepted $event): void
    {
        $workspaceName = $event->specialRequest->workspace?->name ?? 'المساحة';
        NotificationService::createForOffer(
            $event->specialRequest->user_id,
            'accepted',
            $workspaceName
        );
    }
}
