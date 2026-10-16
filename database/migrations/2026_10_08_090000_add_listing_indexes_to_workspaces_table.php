<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index-only migration for the public spaces catalogue.
 *
 * Safe to run against the live table: it adds nothing but secondary indexes —
 * no new columns, no data backfill, no table rewrite. Every field the catalogue
 * needs is derived from columns that already exist, so there is nothing to
 * migrate here, only something to make fast.
 *
 * It is entirely independent of the rest of the work on the catalogue: if the
 * deployment prefers zero migrations, the endpoints return exactly the same
 * data, just with an unindexed scan instead of an indexed one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->index(
                ['status', 'is_active', 'created_at'],
                'workspaces_status_is_active_created_at_index'
            );
        });

        Schema::table('units', function (Blueprint $table) {
            $table->index(
                ['workspace_id', 'status'],
                'units_workspace_id_status_index'
            );
        });

        Schema::table('pricing', function (Blueprint $table) {
            $table->index(
                ['unit_id', 'price_type'],
                'pricing_unit_id_price_type_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropIndex('workspaces_status_is_active_created_at_index');
        });

        Schema::table('units', function (Blueprint $table) {
            $table->dropIndex('units_workspace_id_status_index');
        });

        Schema::table('pricing', function (Blueprint $table) {
            $table->dropIndex('pricing_unit_id_price_type_index');
        });
    }
};