<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiMessage extends Model
{
    protected $fillable = ['ai_conversation_id', 'role', 'content'];

    public function conversation()
    {
        return $this->belongsTo(AiConversation::class);
    }
}