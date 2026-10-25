<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The original enum only allowed all/owners/freelancers. The broadcast API
     * exposes "customers" as an audience, so widen both columns to plain
     * strings. Laravel can change enum -> string on every supported driver
     * (SQLite rebuilds the table and drops the CHECK constraint).
     */
    public function up(): void
    {
        Schema::table('broadcasts', function (Blueprint $table) {
            $table->string('target', 20)->change();
        });

        Schema::table('broadcast_drafts', function (Blueprint $table) {
            $table->string('target', 20)->default('all')->change();
        });
    }

    public function down(): void
    {
        // Widening an enum is not safely reversible once "customers" rows exist.
    }
};
