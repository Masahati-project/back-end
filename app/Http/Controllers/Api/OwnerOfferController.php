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

        $offers = Offer::with('specialRequest')
            ->where('workspace_id', '!=', null)
            ->whereHas('workspace', function ($q) {
                $q->where('owner_id', Auth::id());
            })
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($offer) => $this->formatOfferResponse($offer));

        return response()->json([
            'data' => $offers,
            'offers' => $offers,
        ]);
    }

    private function formatOfferResponse(Offer $offer)
    {
        $requestTitle = $offer->specialRequest?->title ?? '';
        // decimal:2 serialises as a string, so cast back for the UI.
        $price = $offer->price_per_hour !== null ? (float) $offer->price_per_hour : null;

        return [
            'id' => $offer->id,
            'offer_id' => $offer->id,
            'requestId' => $offer->special_request_id,
            'request_id' => $offer->special_request_id,
            'requestTitle' => $requestTitle,
            'request_title' => $requestTitle,
            'title' => $requestTitle,
            'status' => $offer->status,
            'price_per_hour' => $price,
            'price' => $price,
            'currency' => $offer->currency ?? 'ش.ج',
            'duration_hours' => (int) $offer->duration_hours,
            'hours' => (int) $offer->duration_hours,
            'created_at' => $offer->created_at?->toIso8601String(),
            'created' => $offer->created_at?->toIso8601String(),
        ];
    }
}
