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
        Schema::table('reviews', function (Blueprint $table) {
            $table->boolean('visible')->default(true)->after('comment');
            $table->boolean('flagged')->default(false)->after('visible');
            $table->index(['visible', 'flagged']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropIndex(['visible', 'flagged']);
            $table->dropColumn(['visible', 'flagged']);
        });
    }
};
