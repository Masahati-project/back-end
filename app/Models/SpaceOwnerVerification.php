<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpaceOwnerVerification extends Model
{
    /**
     * The table is the singular "space_owner_verification", not the Eloquent
     * default "space_owner_verifications".
     *
     * @var string
     */
    protected $table = 'space_owner_verification';

    /**
     * Without this, every mass-assigned attribute is silently dropped: the
     * framework default guards the model completely, and strict mode is off, so
     * a create() that wrote nothing still looked like it succeeded.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'workspace_id',
        'proof_document_url',
        'status',
        'verified_at',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }
}
