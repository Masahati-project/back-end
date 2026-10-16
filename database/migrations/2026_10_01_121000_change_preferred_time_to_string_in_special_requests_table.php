<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('special_requests')) {
            return;
        }

        Schema::table('special_requests', function (Blueprint $table) {
            $table->string('preferred_time', 120)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('special_requests')) {
            return;
        }

        Schema::table('special_requests', function (Blueprint $table) {
            $table->time('preferred_time')->nullable()->change();
        });
    }
};
