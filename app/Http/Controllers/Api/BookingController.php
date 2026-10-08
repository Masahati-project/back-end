<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Pricing;
use App\Models\Unit;
use App\Models\Workspace;
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
    /**
     * POST /api/bookings
     *
     * The client sends a space-centric payload — space_id, booking_date,
     * start_time, end_time — while the schema is unit-centric: `bookings` has a
     * unit_id and nothing else, and only a Unit carries pricing. Everything here
     * exists to close that gap honestly: resolve space_id to a unit, derive the
     * hours, price the slot server side, and refuse the request rather than guess
     * when the space cannot be booked at all.
     */
    public function store(Request $request)
    {
        // The role gate is inline rather than in a FormRequest on purpose: every
        // FormRequest in this repo returns authorize() => true and the role check
        // is always done in the controller (see OwnerAuthorization::ensureOwnerRole
        // for the mirror image of this, the space_owner side). Only customers book.
        if ($request->user()->role !== 'customer') {
            return response()->json([
                'message' => 'إنشاء الحجز متاح لحسابات العملاء فقط.',
            ], 403);
        }

        $userId = (int) $request->user()->id;
        $idempotencyKey = $this->readIdempotencyKey($request);

        $validated = $request->validate([
            'space_id' => [
                'required',
                'integer',
                // A customer may only book a space that is actually on sale, so
                // "approved and active" is part of the rule itself: an unknown id,
                // a space still pending approval and a deactivated space all fail
                // here with Laravel's normal per-field validation error.
                Rule::exists('workspaces', 'id')
                    ->where('status', 'approved')
                    ->where('is_active', true),
            ],
            // Today is allowed: a customer may book a slot that starts in an hour.
            'booking_date' => ['required', 'string', 'date_format:Y-m-d', 'after_or_equal:today'],
            // Both clock spellings: the app sends "10:00", some older builds and
            // the owner dashboard send "10:00:00".
            'start_time' => ['required', 'string', 'date_format:H:i,H:i:s'],
            'end_time' => ['required', 'string', 'date_format:H:i,H:i:s'],
            // Accepted so the payload the client already sends validates, and then
            // deliberately ignored — see the price computation below.
            'hours' => ['nullable', 'integer', 'min:1'],
            // Optional, see resolveUnit().
            'unit_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        // Replay before any work: a retry of a request that already succeeded must
        // return the booking that already exists, not fall through and hit the
        // overlap check it would now (correctly) be blocked by.
        if ($idempotencyKey !== null) {
            $replay = $this->bookingForIdempotencyKey($userId, $idempotencyKey);

            if ($replay instanceof Booking) {
                return $this->alreadyProcessedResponse($replay);
            }
        }

        // The exists rule above already narrowed the space down to approved and
        // active; it is read again rather than trusted because open_time,
        // close_time and owner_id — all needed below — live on it and are not part
        // of the rule.
        //
        // The deleted_at filter is written out by hand here because Workspace
        // imports SoftDeletes but never uses the trait: the column exists, but
        // nothing filters soft-deleted rows automatically, and a deleted space
        // would otherwise keep taking bookings.
        $workspace = Workspace::where('id', $validated['space_id'])
            ->where('status', 'approved')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->first();

        if (! $workspace) {
            // Shaped like a validation failure because it is one: the client sent
            // a space_id it can no longer book, and the form has to say so on the
            // field rather than as a free-floating banner.
            return $this->fieldError('هذه المساحة غير متاحة للحجز حالياً.', 'space_id');
        }

        [$unit, $unitError] = $this->resolveUnit($workspace, $validated['unit_id'] ?? null);

        if ($unitError instanceof JsonResponse) {
            return $unitError;
        }

        // Times are compared as minutes-from-midnight instead of as datetimes or
        // strings: `open_time`/`close_time` are TIME columns, so MySQL returns
        // "08:00:00" and SQLite "08:00" depending on driver and version. A
        // string comparison of those two is a silent, driver-dependent bug.
        $startMinutes = $this->minutesOfDay($validated['start_time']);
        $endMinutes = $this->minutesOfDay($validated['end_time']);

        // Normalised comparison, so "10:00" and "10:00:00" are the same instant
        // and a zero-length booking is refused rather than stored and billed for
        // 0 hours.
        if ($startMinutes === $endMinutes) {
            return $this->fieldError('وقت النهاية يجب أن يختلف عن وقت البداية.', 'end_time');
        }

        // The payload carries one date and no end date, so the booking cannot
        // cross midnight: end is built on booking_date, which means an end clock
        // earlier than the start clock would produce a backwards interval.
        if ($endMinutes < $startMinutes) {
            return $this->fieldError('وقت النهاية يجب أن يكون بعد وقت البداية.', 'end_time');
        }

        $openMinutes = $this->minutesOfDay($workspace->open_time);
        $closeMinutes = $this->minutesOfDay($workspace->close_time);

        // A negative value means the column could not be read as a clock time at
        // all. Reading that as "open all day" would let a customer book a space
        // whose opening hours nobody ever recorded, so it is refused instead.
        if ($openMinutes < 0 || $closeMinutes < 0) {
            return response()->json([
                'message' => 'ساعات عمل هذه المساحة غير محددة، ولا يمكن إتمام الحجز الآن.',
            ], 422);
        }

        // close_time < open_time is NOT a data error and must not be rejected: it
        // is how a space that is open past midnight is stored (a 24h desk, a
        // night-shift study room: open 20:00, close 04:00). The window simply runs
        // into the next day, so the closing bound is pushed a full day out.
        // open_time == close_time is read the same way, as a 24-hour window.
        $overnight = $closeMinutes <= $openMinutes;
        $closesAt = $closeMinutes + ($overnight ? 1440 : 0);

        if ($startMinutes < $openMinutes || $endMinutes > $closesAt) {
            return response()->json([
                'message' => 'الوقت المختار خارج ساعات عمل المساحة.',
            ], 422);
        }

        $start = Carbon::parse($validated['booking_date'] . ' ' . substr($validated['start_time'], 0, 5));
        $end = Carbon::parse($validated['booking_date'] . ' ' . substr($validated['end_time'], 0, 5));

        // Derived from the two timestamps, never from the `hours` the client sent:
        // a client that under-reports hours would be under-charged, and one that
        // over-reports them would be shown a total that does not match its own
        // price list. diffInMinutes (not the int diffInHours) so a 90 minute slot
        // is billed as 1.5 hours.
        $hours = round($start->diffInMinutes($end) / 60, 2);

        $hourly = Pricing::where('unit_id', $unit->id)
            ->where('price_type', 'hourly')
            ->first();

        // pricing.price is a STRING column, so it has to be cast before it can be
        // used as a number. A unit with no hourly row — or a row whose string is
        // not a positive amount — would otherwise book for 0.00: the customer
        // would get a free slot and the owner would never learn why the booking
        // was worth nothing. Refusing loudly is the only safe reading.
        $hourlyRate = $hourly instanceof Pricing ? (float) $hourly->price : 0.0;

        if ($hourlyRate <= 0) {
            return response()->json([
                'message' => 'لا يوجد سعر ساعي محدد لهذه الوحدة، ولا يمكن إتمام الحجز الآن.',
            ], 422);
        }

        $price = round($hourlyRate * $hours, 2);

        try {
            $result = DB::transaction(function () use ($userId, $validated, $unit, $start, $end, $hours, $price, $idempotencyKey) {
                // ---- the overlap race, and the only place it can be won ---------
                //
                // Two requests for the same unit and overlapping window can both
                // read "this slot is free" at the same instant, and there is
                // nothing in the schema to stop the second insert: no unique
                // constraint can express "no overlapping range" (MySQL has no
                // exclusion constraint, and bookings legitimately overlap for
                // different units of the same space).
                //
                // So the unit row is used as the mutex. `lockForUpdate` takes a
                // row-level write lock that is held until the transaction ends,
                // which serialises every booking attempt for this unit on this one
                // row: the second transaction blocks on the lock, and when it is
                // finally admitted the first booking is committed and therefore
                // visible to the overlap query below, which then reports the slot
                // as taken. The second caller gets 409, not a duplicate row and
                // never a 500.
                //
                // The lock has to be taken BEFORE the overlap query, and it has to
                // be the Unit row: locking `bookings` would be useless because the
                // row that would have to block does not exist yet. One row is
                // locked per transaction and it is locked first, so there is no
                // lock-ordering cycle and no deadlock.
                //
                // Divergence to know about: SQLite ignores FOR UPDATE entirely (its
                // grammar compiles the lock to nothing) and serialises writers at
                // the file level instead, so the suite exercises the surrounding
                // logic on SQLite and the locking itself only matters on MySQL.
                $lockedUnit = Unit::whereKey($unit->id)->lockForUpdate()->first();

                // The unit was deleted (its bookings cascade away with it) between
                // resolving it and taking the lock.
                if (! $lockedUnit) {
                    return ['gone' => true];
                }

                // Half-open comparison: an existing 10:00–13:00 booking conflicts
                // with 09:00–11:00 and with 12:00–14:00, but a slot that starts
                // exactly when it ends does not — the two never share a minute.
                // Cancelled bookings release the slot, which is what makes
                // cancelling and re-booking the same window work.
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

                // GET /api/admin/bookings/{ref} looks the column up directly, so a
                // booking stored without a ref can never be opened from the panel.
                // The value is derived from the primary key, which is only known
                // after the insert.
                $booking->update(['ref' => Booking::generateRef($booking->id)]);

                if ($idempotencyKey !== null) {
                    // Inside the transaction on purpose: if the booking above is
                    // rolled back, so is the claim on the key, and the retry is
                    // free to succeed. The unique index on (user_id,
                    // idempotency_key) is what makes this safe under concurrency.
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
            // The unique index rejected a concurrent request carrying the same
            // Idempotency-Key. The transaction rolled back, so the booking this
            // request was writing is gone and the winner's booking is the only
            // one for that key — read it back and answer as a replay rather than
            // letting a 500 escape. A duplicate-key error only surfaces once the
            // winner has committed (the loser blocks on the index until then), so
            // the row is readable here.
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

        // 409 and not 422: the payload was perfectly valid, the slot is simply
        // taken. The frontend distinguishes the two — 409 means "pick another
        // hour", 422 means "fix the form" — and collapsing them makes a taken
        // slot look like a bug. Message only, no `errors` key: this is a
        // business rule, exactly like OwnerSpaceController::destroy's 409.
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
        // 404 for an id that does not exist, never 403. Answering 403 for a
        // missing id would confirm the id belongs to a real booking, which turns
        // this endpoint into a way to enumerate other customers' reservations.
        $booking = Booking::find($id);

        if (! $booking) {
            return response()->json([
                'message' => 'الحجز غير موجود.',
            ], 404);
        }

        // Only the customer the booking was made for may cancel it. Checked after
        // the 404 so nothing leaks, and before the status rules so a stranger
        // cannot use the 409 to probe which of their bookings are already used.
        if ((int) $booking->user_id !== (int) $request->user()->id) {
            return response()->json([
                'message' => 'لا يمكنك إلغاء حجز لا يخصك.',
            ], 403);
        }

        $booking->loadMissing('unit.workspace.images');

        // Idempotent on purpose. The customer taps "cancel" twice, or the app
        // retries because the first response was lost. Both must succeed: the
        // second tap has nothing left to do, and reporting it as an error would
        // leave a cancelled booking on screen looking like a failed action.
        if ($booking->status === 'cancelled') {
            return response()->json([
                'message' => 'الحجز ملغى مسبقاً.',
                'booking' => $this->formatBookingResponse($booking),
            ], 200);
        }

        // checked_in, completed and disputed describe a slot that was used or is
        // under dispute. Unwinding one of those would contradict the owner's
        // check-in, the revenue the workspace already reported, and any dispute
        // opened against the booking. 409, not 422: the request was well formed,
        // the booking's current state is what refuses it.
        if (! in_array($booking->status, ['pending', 'confirmed'], true)) {
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

    /**
     * Map space_id onto a bookable unit.
     *
     * The contract sends space_id and no unit_id, but `bookings` hangs off Unit
     * and only a Unit carries pricing — so something has to pick the unit. There
     * is exactly one way to do that without inventing data:
     *
     * - Only available units of that space are candidates; an `unavailable` unit
     *   is not bookable regardless of what the client asked for.
     * - No available unit at all -> 422. Booking anyway would either fail on the
     *   pricing join or create a booking nobody can honour.
     * - A `unit_id` in the body is honoured, but only if it belongs to this space
     *   and is available. A unit_id from another space is rejected rather than
     *   silently swapped, or the customer would reserve a desk in one space and
     *   be told they booked another.
     * - Otherwise the first available unit (ordered by id) is used. Falling back
     *   to "first" rather than refusing is deliberate: OwnerSpaceController::store
     *   creates exactly one unit per space, and SpacesController,
     *   CustomerDashboardController and SpecialRequestController::acceptOffer all
     *   read `units->first()`. A space with several units is the exception, and
     *   failing a legitimate customer's booking because the client never knew
     *   units existed would be the worse failure. Ordering by id keeps the choice
     *   deterministic instead of depending on storage order.
     *
     * @return array{0: ?Unit, 1: ?JsonResponse}  The unit, or the response to return.
     */
    private function resolveUnit(Workspace $workspace, ?int $requestedUnitId): array
    {
        $available = Unit::where('workspace_id', $workspace->id)
            ->where('status', 'available')
            ->orderBy('id')
            ->get();

        if ($available->isEmpty()) {
            return [null, response()->json([
                'message' => 'لا توجد وحدات متاحة للحجز في هذه المساحة حالياً.',
            ], 422)];
        }

        if ($requestedUnitId !== null) {
            $unit = $available->firstWhere('id', $requestedUnitId);

            if (! $unit instanceof Unit) {
                return [null, response()->json([
                    'message' => 'الوحدة المحددة غير متاحة في هذه المساحة.',
                ], 422)];
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

        // Validated through the validator rather than $request->validate(), which
        // only reads the body, so the failure has Laravel's usual
        // {message, errors:{…}} shape for a header the client controls.
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

        if (! $row) {
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

        if (! preg_match('/^(\d{1,2}):(\d{2})$/', $clock, $matches)) {
            // No usable opening hours recorded: the space cannot take a booking,
            // and 0 would quietly widen the window to the whole day.
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

        // Derived the same way total_price was computed rather than through
        // Booking::getHoursAttribute(), which truncates with (int) diffInHours:
        // a 90 minute booking would report 1 hour on screen while having been
        // billed for 1.5.
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
            'image' => $space?->images?->first()?->image_url,
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