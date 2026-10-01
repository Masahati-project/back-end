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
        // Guarded so re-running the migration cannot fail on a duplicate email.
        if (DB::table('users')->where('email', 'masahati@outlook.com')->exists()) {
            return;
        }

        DB::table('users')->insert([
            'full_name' => 'أدمن',
            'email' => 'masahati@outlook.com',
            'phone' => '0501234567',
            // No hardcoded password: the account is generated with a random one
            // and the value is read from the environment, so the committed
            // source never contains a credential for the live admin account.
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
