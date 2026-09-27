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
        Schema::create('broadcast_drafts', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120)->nullable();
            $table->text('body')->nullable();
            $table->enum('target', ['all', 'owners', 'freelancers'])->default('all');
            $table->string('link', 300)->nullable();
            $table->json('channels')->nullable(); // ["in_app", "email"]
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('broadcast_drafts');
    }
};
