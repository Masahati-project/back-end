<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpecialRequest extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'description',
        'space_type',
        'capacity',
        'schedule_preset',
        'schedule_count',
        'preferred_time',
        'area',
        'amenities',
        'budget',
        'status',
        'expires_at',
    ];

    protected $casts = [
        'amenities' => 'array',
        'budget' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
