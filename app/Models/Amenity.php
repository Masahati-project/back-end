<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Amenity extends Model
{
    /**
     * The amenities table holds only id, name and icon. It has no created_at /
     * updated_at columns, so Eloquent must not try to write them.
     */
    public $timestamps = false;

    protected $fillable = [
        'name',
        'icon',
    ];

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_amenity');
    }
}
