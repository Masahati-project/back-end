<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminActivity extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'icon',
        'text',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /**
     * Log an admin activity
     */
    public static function log(string $icon, string $text): void
    {
        static::create([
            'icon' => $icon,
            'text' => $text,
            'created_at' => now(),
        ]);
    }
}
