<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('special_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('title');
            $table->text('description');
            $table->enum('space_type', ['room', 'whole']);
            $table->integer('capacity');
            $table->string('schedule_preset')->nullable();
            $table->integer('schedule_count')->nullable();
            $table->time('preferred_time')->nullable();
            $table->string('area')->nullable();
            $table->json('amenities')->nullable();
            $table->decimal('budget', 12, 2)->nullable();
            $table->enum('status', ['open', 'accepted', 'closed'])->default('open');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('special_requests');
    }
};
