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
     * Generate unique booking reference
     */
    public static function generateRef(): string
    {
        $lastBooking = static::latest('id')->first();
        $nextNumber = $lastBooking ? ($lastBooking->id + 1) : 1000;
        return '#BK-' . $nextNumber;
    }

    /**
     * Get hours duration
     */
    public function getHoursAttribute(): int
    {
        return (int) $this->start_datetime->diffInHours($this->end_datetime);
    }
}
