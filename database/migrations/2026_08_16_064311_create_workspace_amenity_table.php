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
        Schema::create('workspace_amenity', function (Blueprint $table) {
            $table->foreignId('workspace_id')->constrained('workspaces', 'id')->cascadeOnDelete();
            $table->foreignId('amenity_id')->constrained('amenity', 'id')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workspace_amenity');
    }
};
