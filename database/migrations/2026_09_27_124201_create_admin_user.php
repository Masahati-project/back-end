<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::table('users')->where('email', 'masahati@outlook.com')->exists()) {
            return;
        }

        DB::table('users')->insert([
            'full_name' => 'أدمن',
            'email' => 'masahati@outlook.com',
            'phone' => '0501234567',
            'password' => Hash::make(env('ADMIN_SEED_PASSWORD')),
            'role' => 'admin',
            'status' => 'active',
            'verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('users')->where('email', 'masahati@outlook.com')->delete();
    }
};
