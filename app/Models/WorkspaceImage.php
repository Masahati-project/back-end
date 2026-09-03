<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceImage extends Model
{
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
