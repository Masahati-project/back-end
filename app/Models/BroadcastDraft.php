<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BroadcastDraft extends Model
{
    protected $fillable = [
        'title',
        'body',
        'target',
        'link',
        'channels',
        'created_by',
    ];

    protected $casts = [
        'channels' => 'array',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
