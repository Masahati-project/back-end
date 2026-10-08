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
        Schema::create('broadcasts', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->text('body');
            $table->enum('target', ['all', 'owners', 'freelancers']);
            $table->string('link', 300)->nullable();
            $table->json('channels'); // ["in_app", "email"]
            $table->integer('total')->default(0);
            $table->integer('opened')->default(0);
            $table->foreignId('sent_by')->constrained('users');
            $table->timestamp('sent_at');

            $table->index('sent_at');
            $table->index('target');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('broadcasts');
    }
};
