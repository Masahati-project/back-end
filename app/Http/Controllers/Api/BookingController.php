<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Pricing;
use App\Models\Unit;
use App\Models\Workspace;
use App\Services\ImageService;
use App\Services\NotificationService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class BookingController extends Controller
{

    public function store(Request $request)
    {
        if ($request->user()->role !== 'customer') {
            return response()->json([
                'message' => 'إنشاء الحجز متاح لحسابات العملاء فقط.',
            ], 403);
        }

        $userId = $request->user()->id;
        $idempotencyKey = $this->readIdempotencyKey($request);

        $validated = $request->validate([
            'space_id' => [
                'required',
                'integer',
                Rule::exists('workspaces', 'id')
                    ->where('status', 'active')
                    ->where('is_active', true),
            ],
            'booking_date' => ['required', 'string', 'date_format:Y-m-d', 'after_or_equal:today'],
            'start_time' => ['required', 'string', 'date_format:H:i,H:i:s'],
            'end_time' => ['required', 'string', 'date_format:H:i,H:i:s'],
            'hours' => ['nullable', 'integer', 'min:1'],
            'unit_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($idempotencyKey !== null) {
            $replay = $this->bookingForIdempotencyKey($userId, $idempotencyKey);

            if ($replay instanceof Booking) {
                return $this->alreadyProcessedResponse($replay);
            }
        }

        $workspace = Workspace::where('id', $validated['space_id'])
            ->where('status', 'active')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->first();

        if (!$workspace) {
            return $this->fieldError('هذه المساحة غير متاحة للحجز حالياً.', 'space_id');
        }

        [$unit, $unitError] = $this->resolveUnit($workspace, $validated['unit_id'] ?? null);

        if ($unitError instanceof JsonResponse) {
            return $unitError;
        }

        $startMinutes = $this->minutesOfDay($validated['start_time']);
        $endMinutes = $this->minutesOfDay($validated['end_time']);

        if ($startMinutes === $endMinutes) {
            return $this->fieldError('وقت النهاية يجب أن يختلف عن وقت البداية.', 'end_time');
        }

        if ($endMinutes < $startMinutes) {
            return $this->fieldError('وقت النهاية يجب أن يكون بعد وقت البداية.', 'end_time');
        }

        $openMinutes = $this->minutesOfDay($workspace->open_time);
        $closeMinutes = $this->minutesOfDay($workspace->close_time);

        if ($openMinutes < 0 || $closeMinutes < 0) {
            return response()->json([
                'message' => 'ساعات عمل هذه المساحة غير محددة، ولا يمكن إتمام الحجز الآن.',
            ], 422);
        }

        $overnight = $closeMinutes <= $openMinutes;
        $closesAt = $closeMinutes + ($overnight ? 1440 : 0);

        if ($startMinutes < $openMinutes || $endMinutes > $closesAt) {
            return response()->json([
                'message' => 'الوقت المختار خارج ساعات عمل المساحة.',
            ], 422);
        }

        $start = Carbon::parse($validated['booking_date'] . ' ' . substr($validated['start_time'], 0, 5));
        $end = Carbon::parse($validated['booking_date'] . ' ' . substr($validated['end_time'], 0, 5));

        $hours = round($start->diffInMinutes($end) / 60, 2);

        $hourly = Pricing::where('unit_id', $unit->id)
            ->where('price_type', 'hourly')
            ->first();

        $hourlyRate = $hourly instanceof Pricing ? (float) $hourly->price : 0.0;

        if ($hourlyRate <= 0) {
            return response()->json([
                'message' => 'لا يوجد سعر ساعي محدد لهذه الوحدة، ولا يمكن إتمام الحجز الآن.',
            ], 422);
        }

        $price = round($hourlyRate * $hours, 2);

        try {
            $result = DB::transaction(function () use ($userId, $validated, $unit, $start, $end, $hours, $price, $idempotencyKey) {
                $lockedUnit = Unit::whereKey($unit->id)->lockForUpdate()->first();

                if (!$lockedUnit) {
                    return ['gone' => true];
                }

                $conflict = Booking::where('unit_id', $lockedUnit->id)
                    ->where('status', '!=', 'cancelled')
                    ->where('start_datetime', '<', $end)
                    ->where('end_datetime', '>', $start)
                    ->exists();

                if ($conflict) {
                    return ['conflict' => true];
                }

                $booking = Booking::create([
                    'user_id' => $userId,
                    'unit_id' => $lockedUnit->id,
                    'start_datetime' => $start,
                    'end_datetime' => $end,
                    'status' => 'pending',
                    'total_price' => $price,
                    'notes' => $validated['notes'] ?? null,
                ]);

                $booking->update(['ref' => Booking::generateRef($booking->id)]);

                if ($idempotencyKey !== null) {
                    DB::table('booking_idempotency_keys')->insert([
                        'user_id' => $userId,
                        'idempotency_key' => $idempotencyKey,
                        'booking_id' => $booking->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                return ['booking' => $booking];
            }, 3);
        } catch (QueryException $e) {
            $replay = $idempotencyKey !== null
                ? $this->bookingForIdempotencyKey($userId, $idempotencyKey)
                : null;

            if ($replay instanceof Booking) {
                return $this->alreadyProcessedResponse($replay);
            }

            throw $e;
        }

        if (isset($result['gone'])) {
            return response()->json([
                'message' => 'لم تعد وحدة هذه المساحة متاحة للحجز.',
            ], 422);
        }

        if (isset($result['conflict'])) {
            return response()->json([
                'message' => 'الفترة المختارة محجوزة مسبقاً في هذه المساحة، يرجى اختيار وقت آخر.',
            ], 409);
        }

        $booking = $result['booking'];
        $booking->loadMissing('unit.workspace.images');

        return response()->json([
            'message' => 'تم إنشاء الحجز بنجاح.',
            'booking' => $this->formatBookingResponse($booking),
        ], 201);
    }

    /**
     * PATCH /api/bookings/{id}/cancel
     *
     * PATCH rather than DELETE, and this is the single agreed cancel route:
     * DELETE would destroy a row that the admin panel, payments and disputes all
     * reference, while PATCH leaves room to carry a cancellation reason in a body
     * later. Nothing in the app deletes a booking.
     *
     * Nothing here is special-cased per creation path: a booking produced by
     * SpecialRequestController::acceptOffer is the same row in the same table with
     * the same columns and the same user_id, so it cancels through this endpoint
     * with no branching.
     */
    public function cancel(Request $request, $id)
    {
        $booking = Booking::find($id);

        if (!$booking) {
            return response()->json([
                'message' => 'الحجز غير موجود.',
            ], 404);
        }

        if ((int) $booking->user_id !== (int) $request->user()->id) {
            return response()->json([
                'message' => 'لا يمكنك إلغاء حجز لا يخصك.',
            ], 403);
        }

        $booking->loadMissing('unit.workspace.images');

        if ($booking->status === 'cancelled') {
            return response()->json([
                'message' => 'الحجز ملغى مسبقاً.',
                'booking' => $this->formatBookingResponse($booking),
            ], 200);
        }

        if (!in_array($booking->status, ['pending', 'confirmed'], true)) {
            return response()->json([
                'message' => 'لا يمكن إلغاء حجز بهذه الحالة.',
            ], 409);
        }

        $booking->update(['status' => 'cancelled']);

        $this->notifyOwnerOfCancellation($booking);

        return response()->json([
            'message' => 'تم إلغاء الحجز بنجاح.',
            'booking' => $this->formatBookingResponse($booking),
        ], 200);
    }
    private function resolveUnit(Workspace $workspace, ?int $requestedUnitId): array
    {
        $available = Unit::where('workspace_id', $workspace->id)
            ->where('status', 'available')
            ->orderBy('id')
            ->get();

        if ($available->isEmpty()) {
            return [
                null,
                response()->json([
                    'message' => 'لا توجد وحدات متاحة للحجز في هذه المساحة حالياً.',
                ], 422)
            ];
        }

        if ($requestedUnitId !== null) {
            $unit = $available->firstWhere('id', $requestedUnitId);

            if (!$unit instanceof Unit) {
                return [
                    null,
                    response()->json([
                        'message' => 'الوحدة المحددة غير متاحة في هذه المساحة.',
                    ], 422)
                ];
            }

            return [$unit, null];
        }

        return [$available->first(), null];
    }

    /**
     * Read and validate the Idempotency-Key header.
     *
     * Absent (or blank) means "no idempotency": the request is processed normally.
     * A blank header is treated as absent rather than stored, because '' would
     * match every later blank-header request and turn ordinary bookings into
     * replays of the first one.
     */
    private function readIdempotencyKey(Request $request): ?string
    {
        $key = $request->header('Idempotency-Key');

        if ($key === null || (is_string($key) && trim($key) === '')) {
            return null;
        }

        Validator::make(
            ['idempotency_key' => $key],
            ['idempotency_key' => ['required', 'string', 'max:255']],
        )->validate();

        return is_string($key) ? trim($key) : null;
    }

    /**
     * The booking an earlier request with this key already produced.
     *
     * Read through the query builder: the table is a lookup index over bookings
     * with no behaviour of its own, so there is nothing for a model to add, and
     * an Eloquent model here would only hide which table is being touched.
     */
    private function bookingForIdempotencyKey(int $userId, string $key): ?Booking
    {
        $row = DB::table('booking_idempotency_keys')
            ->where('user_id', $userId)
            ->where('idempotency_key', $key)
            ->first();

        if (!$row) {
            return null;
        }

        return Booking::find($row->booking_id);
    }

    /**
     * Answer a repeated Idempotency-Key with the original booking and 200.
     *
     * 200 and not a second 201: this request created nothing. 201 means "this
     * request created a resource", and answering it to a retry that created
     * nothing tells the client a new booking exists when it does not — which is
     * what pushes a client into creating one. The booking payload is the original
     * one, so the client can reconcile its list against a booking it already has.
     */
    private function alreadyProcessedResponse(Booking $booking): JsonResponse
    {
        $booking->loadMissing('unit.workspace.images');

        return response()->json([
            'message' => 'تم استلام هذا الطلب مسبقاً، وهذا هو الحجز الأصلي.',
            'booking' => $this->formatBookingResponse($booking),
        ], 200);
    }

    /**
     * A 422 shaped exactly like Laravel's own validation failure.
     *
     * Used for the time-order rules, which are field-level problems discovered
     * after $request->validate() has already run — a client that renders
     * `errors.end_time` needs the key to be there. Business rules that belong to
     * no single field stay message-only.
     */
    private function fieldError(string $message, string $field): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'errors' => [$field => [$message]],
        ], 422);
    }

    /**
     * Minutes since midnight for a TIME column value.
     *
     * MySQL returns "08:00:00", SQLite "08:00" or "08:00:00" depending on the
     * driver build, so the value is truncated to HH:MM and read as a number.
     * Comparing those strings directly would make the opening-hours check
     * depend on the driver.
     */
    private function minutesOfDay($time): int
    {
        $clock = substr(trim((string) $time), 0, 5);

        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $clock, $matches)) {
            return -1;
        }

        return ((int) $matches[1]) * 60 + (int) $matches[2];
    }

    /**
     * Tell the space owner their slot was freed.
     *
     * booking -> unit -> workspace -> owner_id, because a booking hangs off a Unit
     * and only the workspace knows its owner.
     *
     * Goes through NotificationService::create(), the same entry point the offer
     * notifications use (NotificationService::createForOffer and the listeners
     * behind it), so the row is written exactly like every other notification:
     * GET /api/notifications reads user_id / type / message / is_read and there is
     * no second source for them.
     */
    private function notifyOwnerOfCancellation(Booking $booking): void
    {
        $workspace = $booking->unit?->workspace;

        if ($workspace === null || $workspace->owner_id === null) {
            return;
        }

        NotificationService::create(
            (int) $workspace->owner_id,
            'booking_cancelled',
            sprintf(
                'تم إلغاء الحجز %s في «%s» من قِبل العميل.',
                $booking->ref ?: Booking::generateRef($booking->id),
                $workspace->title ?: 'المساحة'
            )
        );
    }

    /**
     * Projects a booking into the shape the frontend reads.
     *
     * Every ambiguous field is emitted under two keys on purpose — id +
     * booking_id, price + cost + total_price, time_from + start_time, date +
     * start_datetime — because the three booking screens in this app disagree:
     * CustomerDashboardController::bookings reads booking_id / space_name /
     * time_from / time_to, OwnerBookingController reads spaceId / timeFrom /
     * price / cost, and the accepted-offer response reads id / date / time /
     * hours / price. A screen reading a key that is not there renders a blank
     * cell with no error anywhere, which is the failure this prevents; the
     * duplicated keys cost one extra value per field.
     *
     * total_price is cast as decimal:2 and therefore serialises as a string
     * ("450.00"), so it is cast back to a float and rounded before it is sent —
     * the client renders it as a number.
     */
    private function formatBookingResponse(Booking $booking): array
    {
        $space = $booking->unit?->workspace;
        $start = $booking->start_datetime;
        $end = $booking->end_datetime;

        $price = $booking->total_price !== null
            ? round((float) $booking->total_price, 2)
            : null;

        $hours = ($start !== null && $end !== null)
            ? round($start->diffInMinutes($end) / 60, 2)
            : null;

        $timeFrom = $start?->format('H:i');
        $timeTo = $end?->format('H:i');

        return [
            'id' => $booking->id,
            'booking_id' => $booking->id,
            'ref' => $booking->ref,
            'status' => $booking->status,
            'space_id' => $space?->id,
            'space_name' => $space?->title,
            'space_location' => $space?->location,
            'image' => ImageService::url($space?->images?->first()?->image_url),
            'unit_id' => $booking->unit_id,
            'date' => $start?->format('Y-m-d'),
            'start_datetime' => $start?->toIso8601String(),
            'end_datetime' => $end?->toIso8601String(),
            'time_from' => $timeFrom,
            'start_time' => $timeFrom,
            'time_to' => $timeTo,
            'end_time' => $timeTo,
            'time' => ($timeFrom !== null && $timeTo !== null)
                ? $timeFrom . ' - ' . $timeTo
                : null,
            'hours' => $hours,
            'duration_hours' => $hours,
            'price' => $price,
            'cost' => $price,
            'total_price' => $price,
            'notes' => $booking->notes,
            'created_at' => $booking->created_at?->toIso8601String(),
        ];
    }
}
