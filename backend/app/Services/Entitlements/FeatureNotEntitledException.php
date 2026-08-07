<?php

namespace App\Services\Entitlements;

use App\Models\Organization;
use RuntimeException;

/**
 * Thrown by `FeatureGate::requireFeature()` when an organisation is not
 * entitled to a feature — the service-layer equivalent of an
 * authorization failure. Nothing in the codebase catches or triggers this
 * in production yet (no controller/module calls `requireFeature()` — see
 * Part 11's worked examples, none of which are wired into a real route;
 * `OrganizationBrandingUrlController` checks `FeatureGate::allows()`
 * directly and writes its own customer-safe 403 instead of calling
 * `requireFeature()`).
 *
 * Error Messaging & Recovery UX, Batch 1 (P0 fix): `getMessage()` previously
 * embedded the raw organisation ID and the raw internal `Feature` key
 * directly in the exception's own message
 * (`"Organisation {$organization->id} is not entitled to feature
 * \"{$featureKey}\"."`) — safe only because nothing forwards it today, but a
 * landmine for the next caller, since several controllers in this codebase
 * already have the habit of catching a typed exception and returning
 * `$e->getMessage()` verbatim as the customer-facing response (see
 * internal-docs/error-messaging-recovery-ux-audit.md §3/§5). `getMessage()`
 * is now a fixed, generic, customer-safe sentence with no organisation or
 * feature-key detail. The organisation and feature key remain fully
 * available via the public readonly properties below for server-side
 * logging/debugging — nothing about entitlement resolution, `FeatureGate`
 * enforcement, snapshots, pricing, or AI Credits changes.
 *
 * `errorCode` mirrors the existing Billing/`account_unavailable` structured
 * `code` convention (never a second envelope shape) — for a future caller
 * that wants deterministic frontend behaviour (e.g. "upgrade your plan")
 * without string-matching `message`. Named `errorCode`, not `code` — the
 * base `Exception` class already declares a (non-readonly, numeric-purpose)
 * `$code` property, which PHP does not allow a subclass to redeclare as
 * `readonly`.
 */
class FeatureNotEntitledException extends RuntimeException
{
    public readonly string $errorCode;

    public function __construct(
        public readonly Organization $organization,
        public readonly string $featureKey,
    ) {
        $this->errorCode = 'feature_not_entitled';

        parent::__construct(
            'This feature is not available on your current plan.'
        );
    }

    /**
     * The organisation ID and feature key, for server-side logging only —
     * never include this in a customer-facing response. See this class's
     * own docblock.
     */
    public function logContext(): array
    {
        return [
            'organization_id' => $this->organization->id,
            'feature_key'     => $this->featureKey,
            'code'            => $this->errorCode,
        ];
    }
}
