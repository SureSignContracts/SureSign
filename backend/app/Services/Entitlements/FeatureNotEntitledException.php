<?php

namespace App\Services\Entitlements;

use App\Models\Organization;
use RuntimeException;

/**
 * Thrown by `FeatureGate::requireFeature()` when an organisation is not
 * entitled to a feature — the service-layer equivalent of an
 * authorization failure. Nothing in the codebase catches or triggers this
 * yet (no controller/module calls `requireFeature()` this checkpoint —
 * see Part 11's worked examples, none of which are wired into a real
 * route).
 */
class FeatureNotEntitledException extends RuntimeException
{
    public function __construct(
        public readonly Organization $organization,
        public readonly string $featureKey,
    ) {
        parent::__construct(
            "Organisation {$organization->id} is not entitled to feature \"{$featureKey}\"."
        );
    }
}
