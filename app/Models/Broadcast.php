<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Broadcast extends Model
{
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
