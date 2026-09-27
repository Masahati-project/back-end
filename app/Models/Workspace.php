<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Workspace extends Model
{
    protected $fillable = [
        'owner_id',
        'space_document_url',
        'title',
        'description',
        'location',
        'latitude',
        'longitude',
        'contact_phone',
        'status',
        'open_time',
        'close_time',
        'is_closed',
        'is_active',
    ];

    protected $casts = [
        'is_closed' => 'boolean',
        'is_active' => 'boolean',
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(WorkspaceImage::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    public function amenities(): BelongsToMany
    {
        return $this->belongsToMany(Amenity::class, 'workspace_amenity');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    // Convenience: bookings through units
    public function bookings(): HasManyThrough
    {
        return $this->hasManyThrough(Booking::class, Unit::class);
    }

    public function favoritedByUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'favorites')->using(Favorite::class)->withTimestamps();
    }

    public function verification(): HasOne
    {
        return $this->hasOne(SpaceOwnerVerification::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    public function ads(): HasMany
    {
        return $this->hasMany(Ad::class, 'space_id');
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->owner_id === $user->id;
    }

    public function getStats()
    {
        $confirmedBookings = $this->bookings()
            ->where('status', 'confirmed')
            ->whereMonth('bookings.created_at', now()->month)
            ->count();

        $revenue = $this->bookings()
            ->where('status', 'confirmed')
            ->whereMonth('bookings.created_at', now()->month)
            ->sum('total_price');

        $totalBookings = $this->bookings()
            ->where('status', 'confirmed')
            ->count();

        $occupancy = $this->units()->count() > 0
            ? round(($confirmedBookings / ($this->units()->count() * 30)) * 100)
            : 0;

        return [
            'bookings' => $confirmedBookings,
            'revenue' => $revenue ?? 0,
            'occupancy' => min($occupancy, 100),
            'totalBookings' => $totalBookings,
        ];
    }
}
