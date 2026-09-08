<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiConversation extends Model
{
    protected $fillable = ['user_id', 'title'];

    public function messages()
    {
        return $this->hasMany(AiMessage::class);
    }
}
