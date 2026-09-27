<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OwnerDocument extends Model
{
    use HasFactory;

    protected $guarded = []; // يسمح بإدخال جميع الحقول دون قيود Mass Assignment
}