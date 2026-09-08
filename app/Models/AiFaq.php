<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiFaq extends Model
{
    protected $fillable = [
        'question',
        'answer',
    ];
}
