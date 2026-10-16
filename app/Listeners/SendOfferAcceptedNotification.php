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
    }

    /**
     * Handle the event.
     */
    public function handle(OfferAccepted $event): void
    {
        $workspace = $event->offer->workspace;

        NotificationService::createForOffer(
            $workspace?->owner_id ?? $event->specialRequest->user_id,
            'accepted',
            $event->specialRequest->title ?? 'المساحة'
        );
    }
}
