<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

/**
 * unit_amount/subtotal_amount/tax_amount/total_amount are integer MINOR
 * units — see App\Support\Billing\Money. status is one of
 * App\Support\Billing\SubscriptionStatus, never a raw Stripe status string
 * (see App\Support\Billing\SubscriptionStatusMapper). source is one of
 * App\Support\Billing\SubscriptionSource — the row's commercial ORIGIN,
 * distinct from provider (which billing integration) and status (current
 * lifecycle state) — write-once, see booted() below.
 */
class Subscription extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'pricing_plan_id',
        'pending_pricing_plan_id',
        'billing_customer_id',
        'provider',
        'source',
        'provider_subscription_id',
        'provider_checkout_session_id',
        'provider_price_id',
        'livemode',
        'internal_reference',
        'status',
        'billing_interval',
        'pending_billing_interval',
        'plan_change_effective_at',
        'currency',
        'unit_amount',
        'quantity',
        'subtotal_amount',
        'tax_amount',
        'total_amount',
        'starts_at',
        'trial_ends_at',
        'current_period_starts_at',
        'current_period_ends_at',
        'cancel_at_period_end',
        'cancelled_at',
        'ended_at',
        'grace_period_ends_at',
        'activated_at',
        'suspended_at',
        'suspension_reason',
        'pending_suspension_reason',
        'pending_suspension_effective_at',
        'plan_code_snapshot',
        'plan_name_snapshot',
        'commercial_terms_json',
        'metadata_json',
        'created_by_user_id',
        'updated_by_user_id',
        'last_transition_occurred_at',
    ];

    protected $casts = [
        'livemode' => 'boolean',
        'unit_amount' => 'integer',
        'quantity' => 'integer',
        'subtotal_amount' => 'integer',
        'tax_amount' => 'integer',
        'total_amount' => 'integer',
        'starts_at' => 'datetime',
        'trial_ends_at' => 'datetime',
        'current_period_starts_at' => 'datetime',
        'current_period_ends_at' => 'datetime',
        'cancel_at_period_end' => 'boolean',
        'cancelled_at' => 'datetime',
        'ended_at' => 'datetime',
        'grace_period_ends_at' => 'datetime',
        'activated_at' => 'datetime',
        'suspended_at' => 'datetime',
        'pending_suspension_effective_at' => 'datetime',
        'plan_change_effective_at' => 'datetime',
        'last_transition_occurred_at' => 'datetime',
        'commercial_terms_json' => 'array',
        'metadata_json' => 'array',
    ];

    /**
     * G4B.1 — source is write-once: fires only on an UPDATE of an
     * already-persisted row (never on creation, hydration, or an unrelated
     * update that doesn't touch source itself) so ordinary lifecycle
     * transitions (status/period dates/etc.) remain completely unaffected.
     * A subscription that changes commercial origin must be ended and
     * replaced by a new Subscription row instead (see SubscriptionSource's
     * own docblock) — never converted in place.
     */
    protected static function booted(): void
    {
        static::updating(function (Subscription $subscription) {
            if ($subscription->isDirty('source')) {
                throw new LogicException('Subscription::source is immutable once persisted — create a new Subscription instead of changing its commercial origin.');
            }
        });
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function pricingPlan()
    {
        return $this->belongsTo(PricingPlan::class);
    }

    /**
     * The plan a scheduled downgrade/plan-change will move to at
     * `plan_change_effective_at` — coexists with the current `pricingPlan()`
     * until then; see SubscriptionLifecycleService::scheduleDowngrade().
     */
    public function pendingPricingPlan()
    {
        return $this->belongsTo(PricingPlan::class, 'pending_pricing_plan_id');
    }

    public function billingCustomer()
    {
        return $this->belongsTo(BillingCustomer::class);
    }

    public function items()
    {
        return $this->hasMany(SubscriptionItem::class);
    }

    public function checkoutSessions()
    {
        return $this->hasMany(BillingCheckoutSession::class, 'subscription_id');
    }

    public function invoices()
    {
        return $this->hasMany(BillingInvoice::class);
    }

    public function payments()
    {
        return $this->hasMany(BillingPayment::class);
    }

    public function entitlementSnapshots()
    {
        return $this->hasMany(SubscriptionEntitlementSnapshot::class);
    }

    /**
     * The most recently effective immutable snapshot — App\Services\
     * Entitlements\FeatureGate's resolution source once one exists (see
     * that class's docblock for the fallback-to-live-PlanEntitlements
     * compatibility behaviour when it doesn't).
     */
    public function currentEntitlementSnapshot()
    {
        return $this->hasOne(SubscriptionEntitlementSnapshot::class)
            ->where('effective_from', '<=', now())
            ->latest('effective_from')
            ->latest('id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
