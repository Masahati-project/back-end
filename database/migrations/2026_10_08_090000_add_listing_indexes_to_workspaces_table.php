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
        // The listing's whole WHERE clause is `status = 'approved' and
        // is_active = 1 and deleted_at is null`, and its default ORDER BY is
        // `created_at desc`. Two equality predicates followed by a sort column
        // is exactly the shape a single composite index serves: the filter is
        // an index range scan and the default sort is already in index order,
        // so the unfiltered first page no longer filesorts. None of those three
        // columns were indexed before.
        Schema::table('workspaces', function (Blueprint $table) {
            $table->index(
                ['status', 'is_active', 'created_at'],
                'workspaces_status_is_active_created_at_index'
            );
        });

        // Every unit lookup made by the listing is "the units of THIS workspace,
        // and are they available": the correlated hourly-price sub-select, the
        // category sub-select (which also takes units by ascending id), and the
        // capacity / instant_booking derivation that keeps only
        // status = 'available'.
        Schema::table('units', function (Blueprint $table) {
            $table->index(
                ['workspace_id', 'status'],
                'units_workspace_id_status_index'
            );
        });

        // The same join as above, narrowed to the one price type the catalogue
        // reads. Without price_type in the index a cheap-looking lookup still
        // has to walk all three price rows of a unit.
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