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
     * Generate unique dispute reference
     */
    public static function generateRef(): string
    {
        $lastDispute = static::latest('id')->first();
        $nextNumber = $lastDispute ? ($lastDispute->id + 1) : 1;
        return '#DIS-' . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
    }
}
