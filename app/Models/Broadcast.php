<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Broadcast extends Model
{
    // The table has neither created_at nor updated_at; it tracks sent_at instead.
    const CREATED_AT = null;
    const UPDATED_AT = null;

    protected $fillable = [
        'title',
        'body',
        'target',
        'link',
        'channels',
        'total',
        'opened',
        'sent_by',
        'sent_at',
    ];

    protected $casts = [
        'channels' => 'array',
        'total' => 'integer',
        'opened' => 'integer',
        'sent_at' => 'datetime',
    ];

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
