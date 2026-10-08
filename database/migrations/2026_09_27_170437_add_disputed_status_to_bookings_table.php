<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add 'disputed' to the status enum. SQLite has no ENUM type and rejects
        // MODIFY COLUMN, so the statement is skipped outside MySQL. The column is
        // created by an earlier migration as a plain string there, which is fine.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE bookings MODIFY COLUMN status ENUM('pending', 'confirmed', 'checked_in', 'completed', 'cancelled', 'disputed') NOT NULL");
        }

        Schema::table('bookings', function (Blueprint $table) {
            $table->string('ref', 20)->unique()->nullable()->after('id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('ref');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE bookings MODIFY COLUMN status ENUM('pending', 'confirmed', 'checked_in', 'completed', 'cancelled') NOT NULL");
        }
    }
};
