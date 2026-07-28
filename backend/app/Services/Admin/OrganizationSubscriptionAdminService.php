<?php

namespace App\Services\Admin;

use App\Models\ActivityLog;
use App\Models\Organization;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Services\Billing\SubscriptionLifecycleService;
use App\Services\Entitlements\SnapshotIntegrityClassifier;
use App\Services\Intelligence\SubscriptionIntelligenceService;
use App\Support\Billing\SubscriptionSource;
use App\Support\Billing\SubscriptionStatus;
use App\Support\Entitlements\SnapshotIntegrityClassification;

/**
 * Phase G4A — read-only Super Admin/Admin organisation subscription
 * administration view. Adds nothing FeatureGate/SubscriptionAccessPolicy
 * don't already compute; it only assembles operator-only diagnostic fields
 * (snapshot metadata, snapshot integrity classification, recent subscription
 * activity) on top of the existing customer-facing
 * SubscriptionIntelligenceService payload, which is reused rather than
 * reimplemented. Never mutates a Subscription/BillingCustomer/snapshot —
 * every field here is read-only, matching this phase's approved scope.
 */
class OrganizationSubscriptionAdminService
{
    public function __construct(
        private readonly SubscriptionIntelligenceService $intelligence,
        private readonly SnapshotIntegrityClassifier $snapshotClassifier,
        private readonly SubscriptionLifecycleService $lifecycle,
    ) {
    }

    public function forOrganization(Organization $organization): array
    {
        // Deliberately the most recent subscription REGARDLESS of status
        // (mirrors BillingOverviewService::currentSubscription()) — an
        // operator viewing this page must be able to see a cancelled/
        // expired subscription's snapshot/activity history too, not only a
        // live one.
        $subscription = $organization->subscriptions()->latest('id')->first();

        return $this->intelligence->intelligenceForOrganization($organization) + [
            'organization_detail' => $this->organizationDetail($organization),
            // G4B.1 — operator-only: commercial origin (stripe/manual/
            // complimentary). Deliberately NOT added to
            // intelligenceForOrganization()'s shared payload, which the
            // customer-facing Billing page also consumes — this field must
            // never reach an ordinary Client-facing surface. Null only for
            // a subscription created before this column existed and not
            // yet backfilled in this environment — the frontend must never
            // render that as "Manual" or "Complimentary".
            'subscription_source' => $subscription?->source,
            'snapshot' => $this->snapshotSummary($subscription),
            'recent_activity' => $this->recentActivity($organization),
            // G4B.2 — reuses SubscriptionLifecycleService::hasConflictingSubscription()
            // directly (the same authority the assignment path itself
            // re-checks) rather than re-deriving "is this organisation
            // eligible" from status/source here.
            'can_assign_subscription' => !$this->lifecycle->hasConflictingSubscription($organization),
            'can_terminate_subscription' => $this->canTerminate($subscription),
            'assignable_plans' => $this->assignablePlans(),
        ];
    }

    /**
     * Terminable only when a subscription exists, is not Stripe-sourced
     * (Stripe subscriptions are cancelled through their own lifecycle —
     * see SubscriptionCancellationService), and is not already in a
     * terminal state (nothing to terminate twice).
     */
    private function canTerminate(?Subscription $subscription): bool
    {
        if ($subscription === null || $subscription->source === SubscriptionSource::STRIPE) {
            return false;
        }

        return !in_array($subscription->status, [SubscriptionStatus::CANCELLED, SubscriptionStatus::EXPIRED], true);
    }

    /**
     * "Marketing visibility must not determine assignability" — deliberately
     * NOT PricingPlan::scopeActive() (that scope also requires
     * is_visible/published_at, a marketing-page concern). Only `status`
     * decides whether Super Admin may assign a plan here.
     */
    private function assignablePlans(): array
    {
        return PricingPlan::query()
            ->where('status', 'active')
            ->orderBy('order')
            ->get(['code', 'name'])
            ->map(fn (PricingPlan $plan) => ['code' => $plan->code, 'name' => $plan->name])
            ->all();
    }

    private function organizationDetail(Organization $organization): array
    {
        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'is_active' => $organization->is_active,
            'contact_name' => $organization->contact_name,
            'email' => $organization->email,
            'created_at' => $organization->created_at,
        ];
    }

    /**
     * Surfaces exactly what Stage 1's report identified as needing
     * visibility: whether a current snapshot exists, and — when it
     * doesn't — whether that's the documented legacy/not-applicable
     * compatibility case or a genuine integrity gap worth an operator's
     * attention (SnapshotIntegrityClassifier is the same authority
     * FeatureGate itself consults; this never invents a second
     * "is this okay" rule).
     */
    private function snapshotSummary(?Subscription $subscription): ?array
    {
        if ($subscription === null) {
            return null;
        }

        $snapshot = $subscription->currentEntitlementSnapshot;
        $classification = $this->snapshotClassifier->classify($subscription);

        return [
            'exists' => $snapshot !== null,
            'source_transition' => $snapshot?->source_transition,
            'lifecycle_reason' => $snapshot?->lifecycle_reason,
            'effective_from' => $snapshot?->effective_from,
            'plan_code_snapshot' => $snapshot?->plan_code_snapshot,
            'integrity_classification' => $classification,
            'is_legacy_fallback' => $snapshot === null && in_array($classification, [
                SnapshotIntegrityClassification::LEGACY_PRE_SNAPSHOT,
                SnapshotIntegrityClassification::NOT_APPLICABLE,
            ], true),
            'requires_attention' => $snapshot === null && in_array($classification, [
                SnapshotIntegrityClassification::EXPECTED_SNAPSHOT_MISSING_RECOVERABLE,
                SnapshotIntegrityClassification::EXPECTED_SNAPSHOT_MISSING_AMBIGUOUS,
            ], true),
        ];
    }

    /**
     * Existing subscription-lifecycle history via ActivityLog — every
     * SubscriptionLifecycleService transition already logs with
     * subject_type = Subscription::class (see that service's own log()
     * method). No new audit architecture is introduced here — G4A's
     * approved scope explicitly excludes that.
     */
    private function recentActivity(Organization $organization): array
    {
        return ActivityLog::query()
            ->where('organization_id', $organization->id)
            ->where('subject_type', Subscription::class)
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (ActivityLog $log) => [
                'action' => $log->action,
                'description' => $log->description,
                'occurred_at' => $log->created_at,
            ])
            ->all();
    }
}
