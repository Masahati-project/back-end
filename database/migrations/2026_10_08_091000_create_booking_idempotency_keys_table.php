<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remembers which Idempotency-Key produced which booking.
 *
 * The booking contract lets the client send an `Idempotency-Key` header with
 * POST /api/bookings. A mobile client that loses the response retries, and a
 * retry must not turn one intent into two bookings — a customer would be
 * charged twice for the same desk and would have to cancel one of them by hand.
 *
 * Nothing on `bookings` can express that on its own: there is no natural unique
 * key to hang the header on (the same slot may legitimately be booked again on
 * another date, by another customer), so the key needs its own table with one
 * row per processed request.
 *
 * The unique index below is what actually enforces idempotency, and it has to be
 * a database constraint rather than a "SELECT then INSERT": two concurrent
 * retries can both read "no row for this key" and both insert. The loser of the
 * race gets a duplicate-key error from the index and the controller re-reads the
 * winner's row instead of creating a second booking.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('booking_idempotency_keys', function (Blueprint $table) {
            $table->id();

            // Scoped per user on purpose. A key is a client's private retry token,
            // not a global token: two customers who happen to generate the same
            // string (a timestamp, a UUIDv4 truncated by a bad client, a shared
            // hard-coded "test-key") must never collide. A global unique index on
            // `idempotency_key` alone would make one customer's retry read as
            // another customer's booking and hand it back to them — a booking
            // that leaks one account's reservation to another.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // 255 is the widest key this endpoint accepts; the controller rejects
            // anything longer with a 422 before it reaches this column.
            $table->string('idempotency_key', 255);

            // The booking this request produced. Cascade: when the booking is
            // deleted there is nothing left to be idempotent about, and keeping
            // the row would make the key look "already processed" forever.
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();

            $table->timestamps();

            // The concurrency guarantee. `user_id` leads so the index also serves
            // the lookup the controller does on every request.
            $table->unique(['user_id', 'idempotency_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_idempotency_keys');
    }
};