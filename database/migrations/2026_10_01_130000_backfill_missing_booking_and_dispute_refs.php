<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Give every historic booking and dispute a usable reference.
     *
     * GET /api/admin/bookings/{ref} and GET /api/admin/disputes/{ref} look the
     * ref column up directly, so a row whose ref is NULL (or an empty string)
     * can never be opened from the admin panel — there is no key to look it up
     * by. Rows written before the reference convention was settled have no ref
     * at all (production booking id 1 is one of them).
     *
     * Deliberate choices, so nobody "fixes" them back:
     *
     * - Only NULL / '' rows are written. A row that already has a ref keeps it,
     *   including legacy values that still start with a "#". Rewriting those
     *   would break links that are already in the admin panel; the controllers
     *   accept both spellings precisely so those rows stay reachable.
     * - chunkById() rather than get(): the loop pages by primary key, so a large
     *   table is never loaded into memory at once and no single UPDATE touches
     *   every row in the table.
     * - The value is built in PHP from the primary key and written with one
     *   UPDATE per row, instead of one big
     *   UPDATE ... SET ref = 'BK-' || id. String concatenation is spelled
     *   differently on MySQL (CONCAT) and SQLite (||), so a single statement
     *   cannot be portable. The query builder also keeps this free of the
     *   models' mass-assignment and casting behaviour.
     * - No model touches, so created_at / updated_at are left alone: this is a
     *   data repair, not an edit, and bumping updated_at would rewrite history.
     */
    public function up(): void
    {
        $this->backfill('bookings', fn (int $id) => 'BK-' . $id);

        $this->backfill(
            'disputes',
            fn (int $id) => 'DIS-' . str_pad((string) $id, 3, '0', STR_PAD_LEFT),
        );
    }

    /**
     * Intentionally a no-op.
     *
     * The obvious "reversal" would set the refs back to NULL, but that is not
     * what running this migration should undo: the rows it filled in were
     * unreachable before it ran, and nulling them again would re-break
     * GET /api/admin/bookings/{ref} for every historic booking while
     * preserving nothing — a NULL ref carries no information that the id does
     * not already carry. A rollback therefore leaves the backfilled references
     * in place; down to the row state of a schema that has never had this
     * migration applied.
     */
    public function down(): void
    {
        //
    }

    /**
     * Write a reference for every row of $table that does not have one yet.
     *
     * Re-running this is a no-op by construction: after the first pass no row
     * matches the NULL / '' filter, so the second pass writes nothing.
     *
     * @param  callable(int): string  $format  Builds the ref from the row's id.
     */
    private function backfill(string $table, callable $format): void
    {
        // The table or column may legitimately be absent (a trimmed install, or
        // a database whose schema has not caught up); nothing to repair then.
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'ref')) {
            return;
        }

        DB::table($table)
            ->select('id')
            ->where(function ($query) {
                $query->whereNull('ref')->orWhere('ref', '');
            })
            ->chunkById(500, function ($rows) use ($table, $format) {
                foreach ($rows as $row) {
                    DB::table($table)
                        ->where('id', $row->id)
                        ->update(['ref' => $format((int) $row->id)]);
                }
            });
    }
};