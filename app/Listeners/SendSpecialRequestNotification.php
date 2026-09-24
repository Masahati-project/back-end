<?php

namespace App\Listeners;

use App\Events\SpecialRequestCreated;
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
        NotificationService::createForSpecialRequest(
            $event->specialRequest->user_id,
            'created',
            $event->specialRequest->title
        );
    }
}
