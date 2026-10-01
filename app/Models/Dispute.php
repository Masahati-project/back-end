<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Dispute extends Model
{
    protected $fillable = [
        'ref',
        'booking_id',
        'user_id',
        'issue',
        'status',
        'admin_note',
        'refund_amount',
        'opened_at',
        'resolved_at',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'resolved_at' => 'datetime',
        'refund_amount' => 'decimal:2',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Build the dispute reference.
     *
     * Takes the id rather than reading the table, because the value is only
     * known after the insert and deriving it from the highest existing id
     * races against concurrent inserts on the unique ref column.
     *
     * No leading "#": the reference travels in the path of
     * /api/admin/disputes/{ref}, and a "#" starts a fragment that the client
     * strips, so "#DIS-001" would be sent as a bare /api/admin/disputes/.
     */
    public static function generateRef(int $id): string
    {
        return 'DIS-' . str_pad((string) $id, 3, '0', STR_PAD_LEFT);
    }
}
