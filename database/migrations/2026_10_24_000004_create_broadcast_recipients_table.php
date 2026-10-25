<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broadcast_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('broadcast_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel'); // in_app or email
            $table->string('status')->default('created'); // created, sent, failed, skipped
            $table->integer('attempts')->default(0);
            $table->text('error')->nullable();
            $table->unsignedBigInteger('notification_id')->nullable();
            $table->timestamps();

            $table->index(['broadcast_id', 'channel']);
            $table->index(['broadcast_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcast_recipients');
    }
};
