<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Offer extends Model
{
    protected $fillable = [
        'special_request_id',
        'workspace_id',
        'price_per_hour',
        'currency',
        'duration_hours',
        'location',
        'notes',
        'rating',
        'status',
    ];

    protected $casts = [
        'price_per_hour' => 'decimal:2',
        'rating' => 'decimal:2',
    ];

    public function specialRequest(): BelongsTo
    {
        return $this->belongsTo(SpecialRequest::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
