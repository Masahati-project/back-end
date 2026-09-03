<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Workspace extends Model
{
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(WorkspaceImage::class);
    }

    public function operatingHours(): HasMany
    {
        return $this->hasMany(OperatingHour::class);
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
}
