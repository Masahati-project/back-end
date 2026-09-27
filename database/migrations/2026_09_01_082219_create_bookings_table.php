<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                  ->constrained()
                  ->cascadeOnDelete();

            $table->foreignId('space_id')
                  ->nullable()
                  ->constrained()
                  ->nullOnDelete();

            // تم إزالة القيد المباشر تجنباً لخطأ الترتيب مع جدول courses
            $table->unsignedBigInteger('course_id')->nullable();

            $table->date('date');
            $table->time('time_from')->nullable();
            $table->time('time_to')->nullable();

            $table->string('status')->default('pending');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};