<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Booking extends Model
{
    protected $fillable = [
        'ref',
        'user_id',
        'unit_id',
        'start_datetime',
        'end_datetime',
        'status',
        'total_price',
        'notes',
    ];

    protected $casts = [
        'start_datetime' => 'datetime',
        'end_datetime' => 'datetime',
        'total_price' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    public function dispute(): HasOne
    {
        return $this->hasOne(Dispute::class);
    }

    public function isForOwner(User $owner): bool
    {
        return $this->unit->workspace->owner_id === $owner->id;
    }

    /**
     * Build the booking reference.
     *
     * The value is derived from the primary key, which the caller only knows
     * after the insert. Computing it from the highest existing id beforehand
     * races: two concurrent bookings read the same "last" row and the unique
     * index on ref then rejects the second insert.
     *
     * No leading "#": the reference travels in the path of
     * GET /api/admin/bookings/{ref}, and everything from a "#" onwards is a
     * fragment that the client strips before the request is sent. A ref of
     * "#BK-1" would therefore be sent as a bare /api/admin/bookings/ and land
     * on the listing route instead of the detail route.
     */
    public static function generateRef(int $id): string
    {
        return 'BK-' . $id;
    }

    /**
     * Get hours duration
     */
    public function getHoursAttribute(): int
    {
        return (int) $this->start_datetime->diffInHours($this->end_datetime);
    }
}
