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
        $workspace = $event->offer->workspace;

        NotificationService::createForOffer(
            $workspace?->owner_id ?? $event->specialRequest->user_id,
            'rejected',
            $workspace?->title ?? 'المساحة'
        );
    }
}
