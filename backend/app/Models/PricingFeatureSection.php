<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PricingFeatureSection extends Model
{
    protected $table = 'pricing_feature_sections';

    protected $fillable = ['name', 'order', 'is_visible'];

    protected $casts = [
        'is_visible' => 'boolean',
    ];

    public function features()
    {
        return $this->hasMany(PricingFeature::class, 'section_id')->orderBy('order');
    }
}
