<?php

namespace App\Services\Entitlements;

use App\Models\Subscription;
use App\Support\Entitlements\EntitlementValue;

/**
 * The future extension point for Enterprise negotiated overrides (Part 8)
 * and Super Admin manual grants (Part 9) — Entitlement Specification v1
 * Sections 9/18. `FeatureGate` consults an implementation of this
 * interface BEFORE falling back to plan defaults, so a future checkpoint
 * that builds real `subscription_overrides` persistence only needs to
 * bind a new implementation here — no change to `FeatureGate` itself, and
 * no change to any future module call site (`FeatureGate::allows(...)`),
 * exactly the "architecture should support this without changing module
 * code" requirement.
 *
 * This checkpoint ships exactly one implementation —
 * `NullEntitlementOverrideRepository`, which always returns no override —
 * since no override storage exists yet (deliberately not built this
 * checkpoint; see `PlanEntitlements`' own docblock for the matching
 * reasoning on why `subscription_entitlements` snapshotting was also
 * deferred).
 */
interface EntitlementOverrideRepository
{
    /**
     * The currently-active override for this subscription/key, or null if
     * none exists (the ordinary case for every subscription today). A
     * real implementation would also need to respect `expires_at` (Section
     * 9) — an expired override must not be returned as active.
     */
    public function findActiveOverride(Subscription $subscription, string $featureKey): ?EntitlementValue;
}
