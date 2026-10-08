<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpaceOwnerVerification extends Model
{
    /**
     * The table is the singular "space_owner_verification", not the Eloquent
     * default "space_owner_verifications".
     *
     * @var string
     */
    protected $table = 'space_owner_verification';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
