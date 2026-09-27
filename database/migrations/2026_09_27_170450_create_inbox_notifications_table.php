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
        Schema::create('inbox_notifications', function (Blueprint $table) {
            $table->id();
            $table->enum('category', ['dispute', 'space_request', 'report']);
            $table->string('title');
            $table->text('body');
            $table->boolean('read')->default(false);
            $table->boolean('archived')->default(false);
            $table->string('ref')->nullable(); // reference to source (dispute id, space id, etc)
            $table->timestamps();

            $table->index(['read', 'archived']);
            $table->index('category');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inbox_notifications');
    }
};
