<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The API allows titles up to 255 characters, but the original columns
     * were string(120) and would truncate or reject longer titles on MySQL.
     */
    public function up(): void
    {
        Schema::table('broadcasts', function (Blueprint $table) {
            $table->string('title', 255)->change();
        });

        Schema::table('broadcast_drafts', function (Blueprint $table) {
            $table->string('title', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('broadcasts', function (Blueprint $table) {
            $table->string('title', 120)->change();
        });

        Schema::table('broadcast_drafts', function (Blueprint $table) {
            $table->string('title', 120)->nullable()->change();
        });
    }
};
