<?php

namespace App\Support\Entitlements;

/**
 * The return shape of `FeatureGate::explain()` — Entitlement
 * Specification v1, Section 14's `explainDecision(string $key): array`,
 * as a typed object rather than an untyped array. Intended for support/
 * debugging and future Super Admin presentation (Section 21) — never
 * exposed to a customer verbatim (it may carry internal reasoning).
 */
final class EntitlementDecision
{
    public function __construct(
        public readonly EntitlementValue $value,
        public readonly ?string $subscriptionStatus,
        public readonly string $reason,
        public readonly ?string $accessMode = null,
        /**
         * Snapshot Integrity & Commercial Automation Hardening checkpoint —
         * one of 'dormant', 'no_subscription', 'not_entitled_by_access_mode',
         * 'override', 'snapshot', 'legacy_live_plan_fallback', or
         * 'missing_required_snapshot' — see `FeatureGate::describeResolutionPath()`.
         * Nullable so existing callers that never asked for it are unaffected.
         */
        public readonly ?string $resolutionPath = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'key' => $this->value->key,
            'value_type' => $this->value->valueType,
            'value' => $this->value->value,
            'is_unlimited' => $this->value->isUnlimited,
            'source' => $this->value->source,
            'is_negotiated_override' => $this->value->isNegotiatedOverride,
            'subscription_status' => $this->subscriptionStatus,
            'access_mode' => $this->accessMode,
            'reason' => $this->reason,
            'resolution_path' => $this->resolutionPath,
        ];
    }
}
