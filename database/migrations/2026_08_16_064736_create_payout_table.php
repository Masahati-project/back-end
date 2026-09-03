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
        Schema::create('payout', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('user', 'id')->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('amount');
            $table->string('currency');
            $table->enum('status', ['pending', 'processed' , 'paid']);
            $table->date('processed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payout');
    }
};
