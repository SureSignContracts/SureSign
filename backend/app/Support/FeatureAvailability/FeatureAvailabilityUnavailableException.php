<?php

namespace App\Support\FeatureAvailability;

/**
 * Thrown by FeatureAvailabilityService::requireAvailable() when a feature is
 * genuinely Maintenance/Coming Soon for the acting user (i.e. not a Super
 * Admin/Admin bypass). Carries only the feature key and status — no
 * internal audit reason, no actor identity — so a catching controller/
 * middleware can build a customer-safe response directly from this
 * exception without needing to re-resolve anything. Mirrors this
 * codebase's existing convention of a small, purpose-built exception with
 * typed public properties rather than overloading ->getMessage() with
 * detail that shouldn't reach the customer (see
 * App\Services\Entitlements\FeatureNotEntitledException).
 */
class FeatureAvailabilityUnavailableException extends \RuntimeException
{
    public function __construct(
        public readonly string $featureKey,
        public readonly string $status,
    ) {
        parent::__construct("Feature \"{$featureKey}\" is currently {$status}.");
    }
}
