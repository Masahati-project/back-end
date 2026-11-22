<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Owner rejection is its own state.
 *
 * Until now an owner rejecting a request wrote `cancelled`, which is the same
 * value a customer cancellation uses — so the customer could not tell "the owner
 * said no" from "I cancelled it", and the notification types diverged from the
 * booking status. `rejected` is now the terminal state for a declined request;
 * `cancelled` stays for customer cancellations.
 */
return new class extends Migration
{
    public function up(): void
    {
        $states = ['pending', 'confirmed', 'checked_in', 'completed', 'cancelled', 'disputed', 'rejected'];

        if (DB::getDriverName() === 'mysql') {
            $quoted = implode(', ', array_map(fn ($state) => "'{$state}'", $states));

            DB::statement("ALTER TABLE bookings MODIFY COLUMN status ENUM({$quoted}) NOT NULL");
        } else {
            Schema::table('bookings', function (Blueprint $table) use ($states) {
                $table->enum('status', $states)->change();
            });
        }
    }

    public function down(): void
    {
        $states = ['pending', 'confirmed', 'checked_in', 'completed', 'cancelled', 'disputed'];

        if (DB::getDriverName() === 'mysql') {
            $quoted = implode(', ', array_map(fn ($state) => "'{$state}'", $states));

            DB::statement("ALTER TABLE bookings MODIFY COLUMN status ENUM({$quoted}) NOT NULL");
        } else {
            Schema::table('bookings', function (Blueprint $table) use ($states) {
                $table->enum('status', $states)->change();
            });
        }
    }
};
