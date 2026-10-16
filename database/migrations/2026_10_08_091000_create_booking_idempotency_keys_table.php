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

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('idempotency_key', 255);

            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();

            $table->timestamps();

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