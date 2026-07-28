<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * See the creating migration's docblock and
 * App\Services\Billing\SubscriptionPlanChangeService — the sole writer of
 * this table's state transitions.
 */
class BillingPlanChange extends Model
{
    protected $fillable = [
        'subscription_id',
        'organization_id',
        'source_pricing_plan_id',
        'target_pricing_plan_id',
        'target_price_mapping_id',
        'change_type',
        'policy',
        'livemode',
        'state',
        'requested_effective_at',
        'requested_by_user_id',
        'requested_at',
        'idempotency_key',
        'attempt_count',
        'sent_at',
        'provider_confirmed_at',
        'applied_at',
        'cancelled_at',
        'superseded_at',
        'failure_code',
        'failure_message',
        'metadata_json',
    ];

    protected $casts = [
        'livemode' => 'boolean',
        'requested_effective_at' => 'datetime',
        'requested_at' => 'datetime',
        'attempt_count' => 'integer',
        'sent_at' => 'datetime',
        'provider_confirmed_at' => 'datetime',
        'applied_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'superseded_at' => 'datetime',
        'metadata_json' => 'array',
    ];

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function sourcePricingPlan()
    {
        return $this->belongsTo(PricingPlan::class, 'source_pricing_plan_id');
    }

    public function targetPricingPlan()
    {
        return $this->belongsTo(PricingPlan::class, 'target_pricing_plan_id');
    }

    public function targetPriceMapping()
    {
        return $this->belongsTo(PricingPlanProviderPrice::class, 'target_price_mapping_id');
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }
}
