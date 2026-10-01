<?php

namespace App\Http\Controllers\Api;

use App\Events\OfferAccepted;
use App\Events\OfferRejected;
use App\Events\SpecialRequestCreated;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSpecialRequestRequest;
use App\Models\Booking;
use App\Models\Offer;
use App\Models\SpecialRequest;
use App\Services\NotificationService;
use App\Traits\OwnerAuthorization;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SpecialRequestController extends Controller
{
    use OwnerAuthorization;

    /**
     * Projects a special request into the shape the frontend reads.
     * Without offers_count the list renders blank cells with no error.
     */
    private function formatRequest(SpecialRequest $request): array
    {
        $labels = [
            'once' => 'مرة واحدة',
            'daily' => 'يومي',
            'weekly' => 'أسبوعي',
            'monthly' => 'شهري',
            'yearly' => 'سنوي',
        ];

        $preset = $request->schedule_preset;
        $count = $request->schedule_count;
        $scheduleLabel = $preset
            ? ($labels[$preset] ?? $preset) . ($count ? ' × ' . $count : '')
            : null;

        return [
            'id' => $request->id,
            'request_id' => $request->id,
            'title' => $request->title,
            'description' => $request->description,
            'space_type' => $request->space_type,
            'capacity' => $request->capacity,
            'schedule_preset' => $preset,
            'schedule_count' => $count,
            'schedule_label' => $scheduleLabel,
            'preferred_time' => $request->preferred_time,
            'area' => $request->area,
            'amenities' => $request->amenities ?? [],
            'budget' => $request->budget,
            'status' => $request->status,
            'offers_count' => $request->offers_count ?? $request->offers()->count(),
            'created_at' => $request->created_at?->format('Y-m-d H:i:s'),
            'expires_at' => $request->expires_at
                ? Carbon::parse($request->expires_at)->format('Y-m-d H:i:s')
                : null,
        ];
    }

    /**
     * Projects an offer into the shape the frontend reads: owner name and avatar,
     * space name and image, plus the pricing the owner quoted.
     */
    private function formatOffer(Offer $offer): array
    {
        $offer->loadMissing('workspace.owner', 'workspace.images');

        $workspace = $offer->workspace;
        $owner = $workspace?->owner;

        // The model casts price to decimal:2, which serialises as a string.
        // The frontend renders it as a number, so cast it back.
        $price = $offer->price_per_hour !== null ? (float) $offer->price_per_hour : null;

        return [
            'id' => $offer->id,
            'offer_id' => $offer->id,
            'request_id' => $offer->special_request_id,
            'owner_name' => $owner?->full_name,
            'owner_avatar' => $owner?->profile_picture_url,
            'space_name' => $workspace?->title,
            'space_image' => $workspace?->images?->first()?->image_url,
            'price_per_hour' => $price,
            'price' => $price,
            'currency' => $offer->currency,
            'duration_hours' => $offer->duration_hours,
            'hours' => $offer->duration_hours,
            'location' => $offer->location ?? $workspace?->location,
            'notes' => $offer->notes,
            'rating' => $offer->rating !== null ? (float) $offer->rating : null,
            'status' => $offer->status,
            'created_at' => $offer->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function pagination($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'total' => $paginator->total(),
        ];
    }

    public function index(Request $request)
    {
        $requests = SpecialRequest::with('offers')
            ->withCount('offers')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 15));

        $formatted = $requests->getCollection()->map(fn($r) => $this->formatRequest($r))->values();

        return response()->json([
            'data' => ['requests' => $formatted],
            'requests' => $formatted,
            'pagination' => $this->pagination($requests),
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
            'message' => 'تم نشر طلبك بنجاح.',
            'request' => $this->formatRequest($specialRequest),
        ], 201);
    }

    /**
     * Marketplace feed: every open request raised by other users.
     */
    public function open(Request $request)
    {
        $this->ensureOwnerRole();

        $requests = SpecialRequest::with('user')
            ->withCount('offers')
            ->where('status', 'open')
            ->where('user_id', '!=', $request->user()->id)
            // A request past its expiry date is no longer open for proposals.
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 15));

        $formatted = $requests->getCollection()->map(fn($r) => $this->formatRequest($r))->values();

        return response()->json([
            'data' => ['requests' => $formatted],
            'requests' => $formatted,
            'pagination' => $this->pagination($requests),
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

        // One offer per owner per request. The frontend hides the button after the
        // first attempt, but this rule is the source of truth.
        $existing = Offer::where('special_request_id', $specialRequest->id)
            ->where('workspace_id', $workspace->id)
            ->exists();

        if ($existing) {
            return response()->json([
                'message' => 'سبق أن قدّمت عرضاً لهذا الطلب.',
            ], 422);
        }

        $offer = Offer::create([
            'special_request_id' => $specialRequest->id,
            'workspace_id' => $workspace->id,
            'price_per_hour' => $validated['price_per_hour'],
            'duration_hours' => $validated['duration_hours'],
            'notes' => $validated['notes'] ?? null,
            'location' => $workspace->location,
            'currency' => $validated['currency'] ?? 'ش.ج',
            'status' => 'pending',
        ]);

        NotificationService::createForOffer(
            $specialRequest->user_id,
            'received',
            $specialRequest->title ?? $workspace->title ?? 'الطلب'
        );

        return response()->json([
            'message' => 'تم إرسال عرضك، وسيصل صاحب الطلب للاختيار.',
            'offer' => $this->formatOffer($offer),
        ], 201);
    }

    public function show(Request $request, $id)
    {
        $specialRequest = SpecialRequest::with('offers.workspace.owner', 'offers.workspace.images')
            ->withCount('offers')
            ->where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $offers = $specialRequest->offers
            ->map(fn($offer) => $this->formatOffer($offer))
            ->values();

        return response()->json([
            'request' => $this->formatRequest($specialRequest),
            // The offers array must be present, otherwise the customer sees an
            // empty list with no error to explain it.
            'offers' => $offers,
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
        $offer = Offer::with('workspace.units', 'workspace.images')
            ->where('id', $offerId)
            ->where('special_request_id', $specialRequest->id)
            ->firstOrFail();

        // An offer that was already rejected or closed cannot be accepted.
        if ($offer->status !== 'pending') {
            return response()->json([
                'message' => 'العرض غير متاح.',
            ], 422);
        }

        $workspace = $offer->workspace;
        $unit = $workspace?->units?->first();

        if (!$unit) {
            return response()->json([
                'message' => 'المساحة المرفقة بالعرض غير متاحة للحجز.',
            ], 422);
        }

        $durationHours = (int) ($offer->duration_hours ?: 1);

        // No preferred date is stored on the request, so the booking starts at the
        // next full hour and runs for the duration the owner quoted.
        $start = Carbon::now()->addHour()->startOfHour();
        $end = $start->copy()->addHours($durationHours);
        $totalPrice = round((float) $offer->price_per_hour * $durationHours, 2);

        $booking = DB::transaction(function () use (
            $request, $specialRequest, $offer, $unit, $start, $end, $totalPrice, $durationHours
        ) {
            $booking = Booking::create([
                'user_id' => $request->user()->id,
                'unit_id' => $unit->id,
                'start_datetime' => $start,
                'end_datetime' => $end,
                'status' => 'pending',
                'total_price' => $totalPrice,
                'notes' => $offer->notes,
            ]);

            $offer->update(['status' => 'accepted']);

            // Only one proposal can win; the rest are closed out.
            $specialRequest->offers()
                ->where('id', '!=', $offer->id)
                ->where('status', 'pending')
                ->update(['status' => 'rejected']);

            $specialRequest->update(['status' => 'accepted']);

            return $booking;
        });

        OfferAccepted::dispatch($specialRequest->fresh(), $offer->fresh());

        $booking->loadMissing('unit.workspace.images');

        return response()->json([
            'message' => 'تم قبول العرض ونقل الحجز إلى حجوزاتك.',
            'booking' => [
                'id' => $booking->id,
                'booking_id' => $booking->id,
                'space_name' => $booking->unit->workspace->title,
                'image' => $booking->unit->workspace->images->first()?->image_url,
                'date' => $start->format('Y-m-d'),
                'time' => $start->format('H:i') . ' – ' . $end->format('H:i'),
                'hours' => $durationHours,
                'price' => $totalPrice,
                'status' => $booking->status,
            ],
            'request' => $this->formatRequest($specialRequest->fresh()),
            'offer' => $this->formatOffer($offer->fresh()),
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
            'message' => 'تم رفض العرض.',
            'request' => $this->formatRequest($specialRequest->fresh()),
            'offer' => $this->formatOffer($offer->fresh()),
        ], 200);
    }

    public function closeRequest(Request $request, $id)
    {
        $specialRequest = SpecialRequest::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $specialRequest->update(['status' => 'closed']);

        // Offers still waiting on a decision are closed along with the request.
        $specialRequest->offers()
            ->where('status', 'pending')
            ->update(['status' => 'closed']);

        return response()->json([
            'message' => 'تم إغلاق الطلب.',
            'request' => $this->formatRequest($specialRequest->fresh()),
        ], 200);
    }
}
