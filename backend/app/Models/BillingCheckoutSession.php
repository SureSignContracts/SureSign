<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingCheckoutSession extends Model
{
    protected $fillable = [
        'organization_id',
        'subscription_id',
        'pricing_plan_id',
        'initiated_by_user_id',
        'provider',
        'provider_checkout_session_id',
        'checkout_url',
        'internal_reference',
        'status',
        'billing_interval',
        'currency',
        'amount',
        'success_url',
        'cancel_url',
        'expires_at',
        'completed_at',
        'metadata_json',
    ];

    protected $casts = [
        'amount' => 'integer',
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
        'metadata_json' => 'array',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    public function pricingPlan()
    {
        return $this->belongsTo(PricingPlan::class);
    }

    public function initiatedBy()
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }
}
