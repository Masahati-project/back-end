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
       Schema::create('study_sessions', function (Blueprint $table) {
    $table->id();

    $table->foreignId('user_id')
        ->constrained()
        ->cascadeOnDelete();

    $table->foreignId('space_id')
        ->nullable()
        ->constrained()
        ->nullOnDelete();

    $table->dateTime('started_at');

    $table->dateTime('ended_at')->nullable();

    $table->integer('duration')->default(0);

    $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('study_sessions');
    }
};
