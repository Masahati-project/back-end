<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Space extends Model
{
    protected $fillable = [
        'title',
        'image',
        'description',
        'rating',
        'location',
        'price',
    ];
}
