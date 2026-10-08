<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An owner is allowed to publish a space before uploading the space document
 * (e.g. a lease contract), so `space_document_url` cannot stay NOT NULL without
 * a default: MySQL strict mode then rejects the INSERT with error 1364 and the
 * API returns 500.
 *
 * Laravel 11+ modifies columns natively, so no doctrine/dbal is required.
 * Both directions are guarded so the migration is idempotent and re-runnable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('workspaces', 'space_document_url')) {
            return;
        }

        if ($this->isSpaceDocumentUrlNullable()) {
            return;
        }

        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('space_document_url')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('workspaces', 'space_document_url')) {
            return;
        }

        if (! $this->isSpaceDocumentUrlNullable()) {
            return;
        }

        // A NOT NULL column cannot hold the NULLs written while the column was
        // nullable, so backfill them before restoring the constraint.
        DB::table('workspaces')
            ->whereNull('space_document_url')
            ->update(['space_document_url' => '']);

        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('space_document_url')->nullable(false)->change();
        });
    }

    /**
     * Best-effort nullability probe. When the driver cannot report it, fall
     * back to "not nullable" so the change() still runs (and stays idempotent).
     */
    private function isSpaceDocumentUrlNullable(): bool
    {
        try {
            foreach (Schema::getColumns('workspaces') as $column) {
                if ($column['name'] === 'space_document_url') {
                    return (bool) $column['nullable'];
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }
};