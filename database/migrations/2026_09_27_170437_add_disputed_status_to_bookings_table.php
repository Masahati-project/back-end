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
        $states = ['pending', 'confirmed', 'checked_in', 'completed', 'cancelled', 'disputed'];

        if (DB::getDriverName() === 'mysql') {
            $quoted = implode(', ', array_map(fn ($state) => "'{$state}'", $states));

            DB::statement("ALTER TABLE bookings MODIFY COLUMN status ENUM({$quoted}) NOT NULL");
        } else {
            Schema::table('bookings', function (Blueprint $table) use ($states) {
                $table->enum('status', $states)->change();
            });
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
        } else {
            Schema::table('bookings', function (Blueprint $table) {
                $table->enum('status', ['pending', 'confirmed', 'checked_in', 'completed', 'cancelled'])->change();
            });
        }
    }
};
