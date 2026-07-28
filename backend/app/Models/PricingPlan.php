<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;

/**
 * annual_price is the total price billed for a full annual term — not a
 * monthly-equivalent figure. Caption it via price_suffix (e.g. "/year").
 */
class PricingPlan extends Model
{
    use SoftDeletes;

    protected $table = 'pricing_plans';

    // Mirrors the migration's column default — without this, a model
    // returned from create() reads `status` as null until reloaded from the
    // database, even though the DB row itself already defaulted to 'draft'.
    protected $attributes = [
        'status' => 'draft',
    ];

    protected $fillable = [
        'code',
        'slug',
        'name',
        'order',
        'monthly_price',
        'annual_price',
        'currency',
        'price_prefix',
        'price_suffix',
        'description',
        'summary',
        'cta_text',
        'cta_url',
        'cta_new_tab',
        'is_visible',
        'is_popular',
        'badge_text',
        'badge_color',
        'accent_color',
        'background_style',
        'icon',
        'custom_label',
        'status',
        'published_at',
    ];

    protected $casts = [
        'monthly_price' => 'decimal:2',
        'annual_price'  => 'decimal:2',
        'cta_new_tab'   => 'boolean',
        'is_visible'    => 'boolean',
        'is_popular'    => 'boolean',
        'published_at'  => 'datetime',
    ];

    public function planFeatures()
    {
        return $this->hasMany(PricingPlanFeature::class, 'plan_id');
    }

    public function providerPrices()
    {
        return $this->hasMany(PricingPlanProviderPrice::class);
    }

    /**
     * Phase G1 — this plan's database-backed entitlement defaults. Never
     * read directly for entitlement resolution; go through
     * App\Services\Entitlements\PlanEntitlementRepository instead.
     */
    public function entitlements()
    {
        return $this->hasMany(PricingPlanEntitlement::class);
    }

    /**
     * Plans eligible for public display: published, active, and not hidden.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active')
            ->where('is_visible', true)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }
}
