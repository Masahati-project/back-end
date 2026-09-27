<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Offer;
use App\Traits\OwnerAuthorization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OwnerOfferController extends Controller
{
    use OwnerAuthorization;


    public function index()
    {
        $this->ensureOwnerRole();

        $offers = Offer::where('workspace_id', '!=', null)
            ->whereHas('workspace', function ($q) {
                $q->where('owner_id', Auth::id());
            })
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($offer) => $this->formatOfferResponse($offer));

        return response()->json(['offers' => $offers]);
    }

    private function formatOfferResponse(Offer $offer)
    {
        return [
            'id' => $offer->id,
            'offer_id' => $offer->id,
            'requestId' => $offer->special_request_id,
            'request_id' => $offer->special_request_id,
            'requestTitle' => $offer->specialRequest?->title ?? '',
            'request_title' => $offer->specialRequest?->title ?? '',
            'title' => $offer->specialRequest?->title ?? '',
            'status' => $offer->status,
            'price_per_hour' => $offer->price_per_hour,
            'price' => $offer->price_per_hour,
            'currency' => $offer->currency ?? 'ش.ج',
            'duration_hours' => $offer->duration_hours,
            'hours' => $offer->duration_hours,
            'created_at' => $offer->created_at?->toIso8601String(),
            'created' => $offer->created_at?->toIso8601String(),
        ];
    }
}
