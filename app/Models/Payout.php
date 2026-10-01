<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payout extends Model
{
    /**
     * The table is the singular "payout", not the Eloquent default "payouts".
     *
     * @var string
     */
    protected $table = 'payout';

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
