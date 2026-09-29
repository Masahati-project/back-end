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
        $workspace = $event->offer->workspace;

        // The offer belongs to the space owner, so that is who gets told. Notifying
        // the request owner would tell the customer their own offer was accepted.
        NotificationService::createForOffer(
            $workspace?->owner_id ?? $event->specialRequest->user_id,
            'accepted',
            $workspace?->title ?? 'المساحة'
        );
    }
}
