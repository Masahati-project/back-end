<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unit extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'workspace_id',
        'type',
        'capacity',
        'has_wifi',
        'has_power',
        'status',
    ];

    protected $casts = [
        'has_wifi' => 'boolean',
        'has_power' => 'boolean',
        'capacity' => 'integer',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function pricing(): HasMany
    {
        return $this->hasMany(Pricing::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
