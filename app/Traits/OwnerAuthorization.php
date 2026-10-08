<?php

namespace App\Traits;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;

trait OwnerAuthorization
{
    public function ensureOwnerRole()
    {
        if (Auth::user()->role !== 'space_owner') {
            throw new AuthorizationException('Unauthorized', 403);
        }
    }

    public function ensureOwnsWorkspace($workspaceId)
    {
        $workspace = \App\Models\Workspace::findOrFail($workspaceId);
        if ($workspace->owner_id !== Auth::id()) {
            abort(403, 'Unauthorized');
        }
        return $workspace;
    }

    public function ensureOwnsBooking($bookingId)
    {
        $booking = \App\Models\Booking::findOrFail($bookingId);
        if (!$booking->isForOwner(Auth::user())) {
            abort(403, 'Unauthorized');
        }
        return $booking;
    }

    public function ensureOwnsOffer($offerId)
    {
        $offer = \App\Models\Offer::findOrFail($offerId);
        if ($offer->workspace->owner_id !== Auth::id()) {
            abort(403, 'Unauthorized');
        }
        return $offer;
    }

    public function ensureOwnsAd($adId)
    {
        $ad = \App\Models\Ad::findOrFail($adId);
        if ($ad->user_id !== Auth::id()) {
            abort(403, 'Unauthorized');
        }
        return $ad;
    }
}
