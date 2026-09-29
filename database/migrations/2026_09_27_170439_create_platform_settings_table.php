<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('commission_rate', 5, 2)->default(12.00); // 12%
            $table->integer('booking_grace_period_hours')->default(24);
            $table->boolean('auto_approve_bookings')->default(false);
            $table->string('currency')->default('ش.ج');
            $table->timestamps();
        });

        // Insert default settings
        DB::table('platform_settings')->insert([
            'commission_rate' => 12.00,
            'booking_grace_period_hours' => 24,
            'auto_approve_bookings' => false,
            'currency' => 'ش.ج',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
