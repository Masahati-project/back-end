<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Broadcast extends Model
{
    const CREATED_AT = null;

    const UPDATED_AT = null;

    protected $fillable = [
        'title',
        'body',
        'target',
        'link',
        'channels',
        'total',
        'recipients_count',
        'in_app_created_count',
        'email_queued_count',
        'email_sent_count',
        'email_failed_count',
        'email_skipped_no_address_count',
        'opened',
        'sent_by',
        'sent_at',
    ];

    protected $casts = [
        'channels' => 'array',
        'total' => 'integer',
        'recipients_count' => 'integer',
        'in_app_created_count' => 'integer',
        'email_queued_count' => 'integer',
        'email_sent_count' => 'integer',
        'email_failed_count' => 'integer',
        'email_skipped_no_address_count' => 'integer',
        'opened' => 'integer',
        'sent_at' => 'datetime',
    ];

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }
}
