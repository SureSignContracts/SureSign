<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PricingIncludedItem extends Model
{
    protected $table = 'pricing_included_items';

    protected $fillable = ['text', 'icon', 'order', 'is_visible'];

    protected $casts = [
        'is_visible' => 'boolean',
    ];
}
