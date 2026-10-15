<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    // The table only has created_at, so Eloquent must not try to write updated_at.
    const UPDATED_AT = null;

    /**
     * Without this every attribute is guarded, and Eloquent's default is to guard
     * everything — so Review::create() threw a MassAssignmentException on its
     * first column. `rating` and `comment` are the only values a client ever
     * sends; the foreign keys are set by the code that writes the row.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'workspace_id',
        'booking_id',
        'rating',
        'comment',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
