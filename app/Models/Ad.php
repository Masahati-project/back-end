<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ad extends Model
{
    protected $fillable = [
        'user_id',
        'space_id',
        'title',
        'description',
        'link',
        'image',
        'target',
        'status',
        'schedule',
        'impressions',
        'sent_at',
    ];

    protected $casts = [
        'schedule' => 'json',
        'sent_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function space()
    {
        return $this->belongsTo(Workspace::class);
    }
}
