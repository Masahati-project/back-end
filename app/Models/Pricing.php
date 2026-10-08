<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pricing extends Model
{
    /**
     * The table is the singular "pricing", not the Eloquent default "pricings".
     *
     * @var string
     */
    protected $table = 'pricing';

    public $timestamps = false;

    protected $fillable = [
        'unit_id',
        'price_type',
        'price',
        'currency',
    ];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
