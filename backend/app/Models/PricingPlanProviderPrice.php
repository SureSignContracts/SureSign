<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

/**
 * unit_amount is integer minor units (see App\Support\Billing\Money) —
 * distinct from pricing_plans.monthly_price/annual_price, which stay
 * decimal major units for display. See the migration's docblock for why a
 * plan may hold several rows over time.
 */
class PricingPlanProviderPrice extends Model
{
    protected $table = 'pricing_plan_provider_prices';

    protected $fillable = [
        'pricing_plan_id',
        'provider',
        'billing_interval',
        'currency',
        'provider_product_id',
        'provider_price_id',
        'livemode',
        'unit_amount',
        'is_active',
        'effective_from',
        'effective_until',
    ];

    protected $casts = [
        'unit_amount' => 'integer',
        'is_active' => 'boolean',
        'livemode' => 'boolean',
        'effective_from' => 'datetime',
        'effective_until' => 'datetime',
    ];

    public function pricingPlan()
    {
        return $this->belongsTo(PricingPlan::class);
    }

    /**
     * The mapping new checkout sessions should use for a given
     * plan/interval/currency — the currently-active one, if any.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Restricts to mappings created under the given Stripe mode — never
     * mix Test Mode and Live Mode mappings in a single resolution query.
     * See App\Services\Billing\PlanPriceMappingService.
     */
    public function scopeForLivemode(Builder $query, bool $livemode): Builder
    {
        return $query->where('livemode', $livemode);
    }
}
