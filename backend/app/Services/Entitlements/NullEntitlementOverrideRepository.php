<?php

namespace App\Services\Entitlements;

use App\Models\Subscription;
use App\Support\Entitlements\EntitlementValue;

/**
 * The default binding (see `App\Providers\EntitlementServiceProvider`) —
 * always reports "no override exists." Every subscription resolves purely
 * from `PlanEntitlements`/the trial profile until a future checkpoint
 * builds real override persistence and binds a different implementation.
 */
class NullEntitlementOverrideRepository implements EntitlementOverrideRepository
{
    public function findActiveOverride(Subscription $subscription, string $featureKey): ?EntitlementValue
    {
        return null;
    }
}
