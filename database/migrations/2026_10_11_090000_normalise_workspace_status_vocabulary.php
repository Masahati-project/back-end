<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Normalises the spaces lifecycle onto the four states the product and the
 * frontend contracts actually speak:
 *
 *   pending    — newly listed, waiting for the admin decision
 *   active     — approved and live (was spelled "approved")
 *   suspended  — was live, admin paused it
 *   rejected   — refused, never went live
 *
 * Two problems are fixed here.
 *
 * 1. `suspended` was not in the enum at all, so an admin suspending a space
 *    could only ever write `rejected` (AdminSpacesController did exactly that,
 *    mapping suspended onto the rejected count). The product treats the two as
 *    distinct: rejection never went live, suspension did.
 *
 * 2. "approved" and "active" were two names for one state, split across the
 *    API boundary. The catalogue filtered on `approved` while the admin API
 *    reported `active`, so the two could never be reconciled by reading one.
 *    The database now carries the same word the API does.
 *
 * The existing `approved` rows are folded into `active` rather than left to
 * fail the widened check constraint.
 */
return new class extends Migration
{
    private const TARGET_STATES = ['pending', 'active', 'suspended', 'rejected'];

    public function up(): void
    {
        if (! Schema::hasTable('workspaces') || ! Schema::hasColumn('workspaces', 'status')) {
            return;
        }

        // Widen before rewriting: MySQL refuses to UPDATE a row into a value the
        // column's current enum does not contain, so this ordering is required,
        // not stylistic.
        if (DB::getDriverName() === 'mysql') {
            $states = implode(', ', array_map(fn ($s) => "'{$s}'", self::TARGET_STATES));

            DB::statement(
                "ALTER TABLE workspaces MODIFY COLUMN status ENUM({$states}) NOT NULL DEFAULT 'pending'"
            );
        } else {
            // Laravel 11+ performs the change natively; on SQLite the grammar
            // rebuilds the table to install the new check constraint.
            Schema::table('workspaces', function (Blueprint $table) {
                $table->enum('status', self::TARGET_STATES)->default('pending')->change();
            });
        }

        DB::table('workspaces')->where('status', 'approved')->update(['status' => 'active']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('workspaces') || ! Schema::hasColumn('workspaces', 'status')) {
            return;
        }

        // A suspended or rejected space has no meaning under the old enum, so
        // they collapse back to pending rather than being silently dropped.
        DB::table('workspaces')->whereIn('status', ['suspended', 'rejected'])
            ->update(['status' => 'pending']);

        DB::table('workspaces')->where('status', 'active')->update(['status' => 'approved']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE workspaces MODIFY COLUMN status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending'"
            );
        } else {
            Schema::table('workspaces', function (Blueprint $table) {
                $table->enum('status', ['pending', 'approved', 'rejected'])
                    ->default('pending')
                    ->change();
            });
        }
    }
};