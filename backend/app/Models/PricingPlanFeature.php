<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PricingPlanFeature extends Model
{
    protected $table = 'pricing_plan_features';

    protected $fillable = ['plan_id', 'feature_id', 'status', 'value_text', 'icon_override'];

    public function plan()
    {
        return $this->belongsTo(PricingPlan::class, 'plan_id');
    }

    public function feature()
    {
        return $this->belongsTo(PricingFeature::class, 'feature_id');
    }
}
