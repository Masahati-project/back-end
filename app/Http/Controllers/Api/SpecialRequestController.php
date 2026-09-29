<?php

namespace App\Http\Controllers\Api;

use App\Events\OfferAccepted;
use App\Events\OfferRejected;
use App\Events\SpecialRequestCreated;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSpecialRequestRequest;
use App\Models\Offer;
use App\Models\SpecialRequest;
use App\Services\NotificationService;
use App\Traits\OwnerAuthorization;
use Illuminate\Http\Request;

class SpecialRequestController extends Controller
{
    use OwnerAuthorization;

    public function index(Request $request)
    {
        $requests = SpecialRequest::with(['user', 'offers'])
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'requests' => $requests,
            'message' => 'تم جلب الطلبات الخاصة بنجاح',
        ], 200);
    }

    public function store(StoreSpecialRequestRequest $request)
    {
        $specialRequest = SpecialRequest::create(array_merge(
            $request->validated(),
            ['user_id' => $request->user()->id, 'status' => 'open']
        ));

        SpecialRequestCreated::dispatch($specialRequest);

        return response()->json([
            'request' => $specialRequest,
            'message' => 'تم إنشاء الطلب الخاص بنجاح',
        ], 201);
    }

    /**
     * Marketplace feed: every open request raised by other users.
     */
    public function open(Request $request)
    {
        $requests = SpecialRequest::with(['user', 'offers'])
            ->where('status', 'open')
            ->where('user_id', '!=', $request->user()->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'requests' => $requests,
            'message' => 'تم جلب الطلبات المفتوحة بنجاح',
        ], 200);
    }

    /**
     * A space owner submits (or revises) their proposal against an open request.
     */
    public function storeOffer(Request $request, $requestId)
    {
        $this->ensureOwnerRole();

        $validated = $request->validate([
            'space_id' => ['required', 'integer', 'exists:workspaces,id'],
            'price_per_hour' => ['required', 'numeric', 'min:0'],
            'duration_hours' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'currency' => ['nullable', 'string', 'max:3'],
        ]);

        $specialRequest = SpecialRequest::findOrFail($requestId);

        if ($specialRequest->user_id === $request->user()->id) {
            return response()->json([
                'message' => 'لا يمكنك تقديم عرض على طلبك الخاص',
            ], 403);
        }

        if ($specialRequest->status !== 'open') {
            return response()->json([
                'message' => 'هذا الطلب مغلق ولا يقبل عروضاً جديدة',
            ], 422);
        }

        $workspace = $this->ensureOwnsWorkspace($validated['space_id']);

        // Re-submitting from the same space revises the existing proposal rather
        // than failing, otherwise a typo in the price would permanently block
        // the owner from bidding.
        $offer = Offer::updateOrCreate(
            [
                'special_request_id' => $specialRequest->id,
                'workspace_id' => $workspace->id,
            ],
            [
                'price_per_hour' => $validated['price_per_hour'],
                'duration_hours' => $validated['duration_hours'],
                'notes' => $validated['notes'] ?? null,
                'currency' => $validated['currency'] ?? 'ش.ج',
                'status' => 'pending',
            ]
        );

        NotificationService::createForOffer(
            $specialRequest->user_id,
            'received',
            $workspace->title ?? 'المساحة'
        );

        return response()->json([
            'message' => 'تم إرسال العرض بنجاح',
            'offer' => $offer,
        ], 201);
    }

    public function show(Request $request, $id)
    {
        $specialRequest = SpecialRequest::with(['user', 'offers'])
            ->where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        return response()->json([
            'request' => $specialRequest,
            'message' => 'تم جلب الطلب الخاص بنجاح',
        ], 200);
    }

    public function acceptOffer(Request $request, $requestId, $offerId)
    {
        $specialRequest = SpecialRequest::where('id', $requestId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        // Scoping the lookup to the request stops an offer from another request
        // being accepted through a mismatched {requestId}/{offerId} pair.
        $offer = Offer::where('id', $offerId)
            ->where('special_request_id', $specialRequest->id)
            ->firstOrFail();

        $offer->update(['status' => 'accepted']);

        // Only one proposal can win; the rest are closed out.
        $specialRequest->offers()
            ->where('id', '!=', $offer->id)
            ->where('status', 'pending')
            ->update(['status' => 'rejected']);

        $specialRequest->update(['status' => 'accepted']);

        OfferAccepted::dispatch($specialRequest, $offer);

        return response()->json([
            'request' => $specialRequest->fresh(),
            'offer' => $offer->fresh(),
            'message' => 'تم قبول العرض بنجاح',
        ], 200);
    }

    public function rejectOffer(Request $request, $requestId, $offerId)
    {
        $specialRequest = SpecialRequest::where('id', $requestId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $offer = Offer::where('id', $offerId)
            ->where('special_request_id', $specialRequest->id)
            ->firstOrFail();

        // Rejecting one proposal leaves the request open for other owners.
        $offer->update(['status' => 'rejected']);

        OfferRejected::dispatch($specialRequest, $offer);

        return response()->json([
            'request' => $specialRequest->fresh(),
            'offer' => $offer->fresh(),
            'message' => 'تم رفض العرض بنجاح',
        ], 200);
    }

    public function closeRequest(Request $request, $id)
    {
        $specialRequest = SpecialRequest::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $specialRequest->update(['status' => 'closed']);

        return response()->json([
            'request' => $specialRequest->fresh(),
            'message' => 'تم إغلاق الطلب الخاص بنجاح',
        ], 200);
    }
}
