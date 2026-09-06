<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pricing extends Model
{
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
