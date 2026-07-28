<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * An immutable, point-in-time record of a subscription's resolved
 * entitlements — see the creating migration's docblock and
 * App\Services\Entitlements\EntitlementSnapshotService, the only writer.
 * Never updated after creation: a new commercial event always produces a
 * NEW row, never a mutation of an existing one (Subscription Commercial
 * State Automation checkpoint, Part 7/8).
 */
class SubscriptionEntitlementSnapshot extends Model
{
    protected $table = 'billing_entitlement_snapshots';

    protected $fillable = [
        'subscription_id',
        'organization_id',
        'pricing_plan_id',
        'plan_code_snapshot',
        'entitlements_json',
        'effective_from',
        'lifecycle_reason',
        'source_transition',
    ];

    protected $casts = [
        'entitlements_json' => 'array',
        'effective_from' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('SubscriptionEntitlementSnapshot rows are immutable — create a new snapshot instead of updating one.');
        });
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function pricingPlan()
    {
        return $this->belongsTo(PricingPlan::class);
    }
}
