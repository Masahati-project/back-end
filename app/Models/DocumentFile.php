<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentFile extends Model
{
    protected $fillable = [
        'document_id',
        'slot_id',
        'name',
        'path',
        'size',
        'mime_type',
    ];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }
}
