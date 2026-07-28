<?php

namespace App\Services\Intelligence;

use App\Models\Organization;
use App\Models\Subscription;
use App\Services\Entitlements\SubscriptionAccessPolicy;
use App\Support\Billing\SubscriptionStatus;
use App\Support\Entitlements\SubscriptionAccessMode;
use App\Support\Intelligence\EntitlementHealthStatus;

/**
 * Phase G3, Stage 7 — a health overview generated entirely from data that
 * already has an authoritative source elsewhere: the subscription's own
 * `status` (`SubscriptionAccessPolicy`, unchanged), the presence of a
 * `BillingCustomer` (Subscription & Billing foundation, unchanged), and
 * the per-key usage/limit computation `UsageMetricsService` already
 * produced. This service introduces no new source of truth — it only
 * classifies existing signals into a health status and assembles them
 * into one list for the dashboard.
 */
class SubscriptionHealthService
{
    public function __construct(
        private readonly SubscriptionAccessPolicy $accessPolicy,
        private readonly UsageMetricsService $usageMetrics,
    ) {
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, overall: string}
     */
    public function healthForOrganization(Organization $organization, ?Subscription $subscription): array
    {
        $items = [
            $this->subscriptionHealth($subscription),
            $this->billingHealth($subscription),
            $this->stripeHealth($organization),
        ];

        foreach ($this->usageMetrics->usageForOrganization($organization) as $usage) {
            if ($usage['used'] === null) {
                continue; // not yet measurable — nothing to report health on
            }

            $items[] = [
                'key' => "usage.{$usage['feature_key']}",
                'label' => "{$usage['display_name']} usage",
                // With no subscription at all there is no plan allowance to
                // measure usage against — reporting a percentage-derived
                // "healthy" here would imply a commercial relationship that
                // doesn't exist. Mirrors subscription/billing/stripe above.
                'status' => $subscription === null ? EntitlementHealthStatus::UNKNOWN : $usage['status'],
                'detail' => $this->usageHealthDetail($usage),
            ];
        }

        return [
            'items' => $items,
            'overall' => $this->overallStatus($items),
        ];
    }

    private function subscriptionHealth(?Subscription $subscription): array
    {
        $mode = $this->accessPolicy->resolve($subscription)->mode;

        $status = match ($mode) {
            SubscriptionAccessMode::FULL, SubscriptionAccessMode::TRIAL => EntitlementHealthStatus::HEALTHY,
            SubscriptionAccessMode::GRACE => EntitlementHealthStatus::WARNING,
            SubscriptionAccessMode::RESTRICTED => EntitlementHealthStatus::CRITICAL,
            default => EntitlementHealthStatus::UNKNOWN, // NONE — no subscription at all
        };

        return [
            'key' => 'subscription',
            'label' => 'Subscription',
            'status' => $status,
            'detail' => $this->accessPolicy->resolve($subscription)->reason,
        ];
    }

    private function billingHealth(?Subscription $subscription): array
    {
        if ($subscription === null) {
            return ['key' => 'billing', 'label' => 'Billing', 'status' => EntitlementHealthStatus::UNKNOWN, 'detail' => 'No subscription yet.'];
        }

        $status = match ($subscription->status) {
            SubscriptionStatus::PAST_DUE => EntitlementHealthStatus::WARNING,
            SubscriptionStatus::UNPAID, SubscriptionStatus::SUSPENDED => EntitlementHealthStatus::CRITICAL,
            default => EntitlementHealthStatus::HEALTHY,
        };

        $detail = match ($subscription->status) {
            SubscriptionStatus::PAST_DUE => 'A recent payment failed — a retry is expected before your grace period ends.',
            SubscriptionStatus::UNPAID => 'Payment has failed and no further automatic retries remain.',
            SubscriptionStatus::SUSPENDED => 'Your subscription is suspended pending manual review.',
            default => 'No billing issues detected.',
        };

        return ['key' => 'billing', 'label' => 'Billing', 'status' => $status, 'detail' => $detail];
    }

    private function stripeHealth(Organization $organization): array
    {
        $billingCustomer = $organization->billingCustomer;
        $connected = $billingCustomer !== null && $billingCustomer->provider_customer_id !== null;

        return [
            'key' => 'stripe',
            'label' => 'Stripe connection',
            'status' => $connected ? EntitlementHealthStatus::HEALTHY : EntitlementHealthStatus::UNKNOWN,
            'detail' => $connected ? 'Connected to Stripe.' : 'No Stripe customer connected yet.',
        ];
    }

    private function usageHealthDetail(array $usage): string
    {
        if ($usage['is_unlimited']) {
            return "Unlimited {$usage['display_name']}.";
        }

        $percent = $usage['percent_used'];

        if ($percent === null) {
            return "{$usage['used']} used.";
        }

        return match (true) {
            $percent >= 100 => "You have exceeded your {$usage['display_name']} allowance ({$percent}% used).",
            $percent >= 95 => "You have used {$percent}% of your {$usage['display_name']} allowance — nearly full.",
            $percent >= 80 => "You have used {$percent}% of your {$usage['display_name']} allowance.",
            default => "You have used {$percent}% of your {$usage['display_name']} allowance.",
        };
    }

    private function overallStatus(array $items): string
    {
        $statuses = array_column($items, 'status');

        return match (true) {
            in_array(EntitlementHealthStatus::EXCEEDED, $statuses, true) => EntitlementHealthStatus::EXCEEDED,
            in_array(EntitlementHealthStatus::CRITICAL, $statuses, true) => EntitlementHealthStatus::CRITICAL,
            in_array(EntitlementHealthStatus::WARNING, $statuses, true) => EntitlementHealthStatus::WARNING,
            // Every signal unknown (no subscription at all) — never report "healthy"
            // for an organisation with no commercial relationship to measure.
            array_diff($statuses, [EntitlementHealthStatus::UNKNOWN]) === [] => EntitlementHealthStatus::UNKNOWN,
            default => EntitlementHealthStatus::HEALTHY,
        };
    }
}
