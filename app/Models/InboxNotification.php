<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InboxNotification extends Model
{
    protected $fillable = [
        'category',
        'title',
        'body',
        'read',
        'archived',
        'ref',
    ];

    protected $casts = [
        'read' => 'boolean',
        'archived' => 'boolean',
    ];

    /**
     * Create a new inbox notification
     */
    public static function notify(string $category, string $title, string $body, ?string $ref = null): void
    {
        static::create([
            'category' => $category,
            'title' => $title,
            'body' => $body,
            'ref' => $ref,
        ]);
    }
}
