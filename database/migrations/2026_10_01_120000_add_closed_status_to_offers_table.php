<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('offers')) {
            return;
        }

        // The frontend expects `closed` for offers that were still pending when
        // the customer closed the request. The original enum only allowed
        // pending/accepted/rejected, so writing `closed` failed on MySQL.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE offers MODIFY COLUMN status ENUM('pending', 'accepted', 'rejected', 'closed') NOT NULL DEFAULT 'pending'");
            return;
        }

        // SQLite has no MODIFY COLUMN, and Laravel encodes enum as a CHECK
        // constraint, so the table has to be rebuilt to widen the allowed set.
        if (DB::getDriverName() === 'sqlite') {
            DB::statement("PRAGMA foreign_keys=OFF");

            DB::statement("CREATE TABLE offers__tmp (
                id integer primary key autoincrement,
                special_request_id integer not null,
                workspace_id integer not null,
                price_per_hour numeric not null,
                currency varchar not null default 'USD',
                duration_hours integer not null,
                location varchar null,
                notes text null,
                rating numeric null,
                status varchar not null default 'pending'
                    check (status in ('pending', 'accepted', 'rejected', 'closed')),
                created_at datetime null,
                foreign key(special_request_id) references special_requests(id) on delete cascade,
                foreign key(workspace_id) references workspaces(id) on delete cascade
            )");

            DB::statement("INSERT INTO offers__tmp SELECT id, special_request_id, workspace_id, price_per_hour, currency, duration_hours, location, notes, rating, status, created_at FROM offers");

            DB::statement("DROP TABLE offers");
            DB::statement("ALTER TABLE offers__tmp RENAME TO offers");
            DB::statement("CREATE INDEX offers_special_request_id_foreign ON offers (special_request_id)");
            DB::statement("CREATE INDEX offers_workspace_id_foreign ON offers (workspace_id)");

            DB::statement("PRAGMA foreign_keys=ON");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql' && Schema::hasTable('offers')) {
            // Fold any closed offers back to rejected so the narrower enum fits.
            DB::table('offers')->where('status', 'closed')->update(['status' => 'rejected']);
            DB::statement("ALTER TABLE offers MODIFY COLUMN status ENUM('pending', 'accepted', 'rejected') NOT NULL DEFAULT 'pending'");
        }
    }
};
