<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PricingFaq extends Model
{
    protected $table = 'pricing_faqs';

    protected $fillable = ['question', 'answer', 'order', 'is_enabled'];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];
}
