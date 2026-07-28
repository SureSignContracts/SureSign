<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PricingFeature extends Model
{
    protected $table = 'pricing_features';

    protected $fillable = ['section_id', 'name', 'order', 'is_visible'];

    protected $casts = [
        'is_visible' => 'boolean',
    ];

    public function section()
    {
        return $this->belongsTo(PricingFeatureSection::class, 'section_id');
    }

    public function planFeatures()
    {
        return $this->hasMany(PricingPlanFeature::class, 'feature_id');
    }
}
