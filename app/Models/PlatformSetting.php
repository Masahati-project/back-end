<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformSetting extends Model
{
    protected $fillable = [
        'commission_rate',
        'booking_grace_period_hours',
        'auto_approve_bookings',
        'currency',
    ];

    protected $casts = [
        'commission_rate' => 'decimal:2',
        'booking_grace_period_hours' => 'integer',
        'auto_approve_bookings' => 'boolean',
    ];

    /**
     * Get the singleton settings instance.
     *
     * Recreates the row when it is missing, because callers read properties such
     * as commission_rate straight off the result and a null would throw a 500.
     */
    public static function current()
    {
        return static::first() ?? static::create([
            'commission_rate' => 12.00,
            'booking_grace_period_hours' => 24,
            'auto_approve_bookings' => false,
            'currency' => 'ش.ج',
        ]);
    }
}
