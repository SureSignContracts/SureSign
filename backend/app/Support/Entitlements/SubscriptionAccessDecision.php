<?php

namespace App\Support\Entitlements;

/**
 * The output of `App\Services\Entitlements\SubscriptionAccessPolicy::resolve()`
 * — which commercial access mode currently applies, and why. Consumed by
 * `FeatureGate` to decide whether to resolve plan/trial entitlements and
 * whether an override may apply at all (`SubscriptionAccessMode::allowsOverrides()`),
 * and by `EntitlementDecision`/`FeatureGate::explain()` for
 * human-readable explainability (Part 13).
 */
final class SubscriptionAccessDecision
{
    public function __construct(
        public readonly string $mode,
        public readonly ?string $subscriptionStatus,
        public readonly string $reasonCode,
        public readonly string $reason,
    ) {
    }
}
