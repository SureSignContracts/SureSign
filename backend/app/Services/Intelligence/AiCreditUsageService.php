<?php

namespace App\Services\Intelligence;

use App\Models\AiCreditLedgerEntry;
use App\Models\User;
use App\Services\Entitlements\FeatureGate;
use App\Services\Entitlements\SubscriptionAccessPolicy;
use App\Support\AI\AiCreditOperatingMode;
use App\Support\AI\AiCreditTransactionType;
use App\Support\Entitlements\Feature;
use App\Support\Entitlements\SubscriptionAccessMode;
use App\Support\Intelligence\EntitlementHealthStatus;
use Illuminate\Support\Carbon;

/**
 * Entitlement Specification v1 §4a / AI Credit Policy Part Ten (G4C.3E) —
 * the ONLY source of the customer-facing "Monthly AI Usage" presentation
 * model. Owns both the current-period usage query and the presentation
 * shaping itself — deliberately NOT delegated to AiCreditBalanceService,
 * which stays focused exclusively on all-time balance computation (issued/
 * consumed/reserved/available). The balance engine and this usage-
 * reporting engine are separate responsibilities on purpose: a future
 * change to either must never risk silently affecting the other.
 *
 * Never returns the raw allowance or raw used-credit figures — only a
 * derived, 0-100-clamped percentage. Organisation is always resolved from
 * the authenticated User, never accepted as a parameter from a caller —
 * mirrors SubscriptionIntelligenceService's existing tenancy contract
 * exactly.
 */
class AiCreditUsageService
{
    public function __construct(
        private readonly FeatureGate $featureGate,
        private readonly SubscriptionAccessPolicy $accessPolicy,
    ) {
    }

    /**
     * @return array{available: bool, usage_percent: ?int, resets_at: ?string, status: string, enforcement_enabled: bool}
     */
    public function usageFor(User $user): array
    {
        // customer_meter_enabled and the operating mode are deliberately
        // independent — the meter may be shown while the mode stays SHADOW
        // (or even DISABLED); neither implies the other.
        if (!config('ai_credit_shadow.customer_meter_enabled', false)) {
            return $this->unavailable();
        }

        $organization = $user->organization;

        if ($organization === null) {
            return $this->unavailable();
        }

        $subscription = $organization->subscriptions()->latest('id')->first();
        $decision = $this->accessPolicy->resolve($subscription);

        if (!in_array($decision->mode, [SubscriptionAccessMode::FULL, SubscriptionAccessMode::TRIAL, SubscriptionAccessMode::GRACE], true)) {
            return $this->unavailable();
        }

        $entitlement = $this->featureGate->limit($organization, Feature::AI_CREDITS_PER_MONTH);

        if ($entitlement->isUnlimited || $entitlement->value === null || (int) $entitlement->value <= 0) {
            return $this->unavailable();
        }

        $allowance = (int) $entitlement->value;

        [$periodStart, $periodEnd] = $this->currentCalendarMonthWindow();

        $used = (float) AiCreditLedgerEntry::query()
            ->where('organization_id', $organization->id)
            ->where('transaction_type', AiCreditTransactionType::SETTLE)
            ->where('created_at', '>=', $periodStart)
            ->where('created_at', '<', $periodEnd)
            ->sum('amount');

        $rawPercent = ($used / $allowance) * 100;
        $usagePercent = (int) round(min(100, max(0, $rawPercent)));

        return [
            'available' => true,
            'usage_percent' => $usagePercent,
            'resets_at' => $periodEnd->toIso8601String(),
            'status' => EntitlementHealthStatus::forPercentUsed((float) $usagePercent),
            'enforcement_enabled' => AiCreditOperatingMode::isEnforced(),
        ];
    }

    /**
     * UTC calendar month — matching Feature::AI_ANALYSES_PER_MONTH's
     * existing, already-decided reset convention (Entitlement
     * Specification v1 §12) exactly, so both meters agree on when a month
     * boundary falls even though they measure different things. No
     * subscription-billing-anniversary window here by design — see the
     * Entitlement Specification's §4a amendment for the reasoning.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function currentCalendarMonthWindow(): array
    {
        $start = Carbon::now('UTC')->startOfMonth();

        return [$start, $start->copy()->addMonth()];
    }

    private function unavailable(): array
    {
        return [
            'available' => false,
            'usage_percent' => null,
            'resets_at' => null,
            'status' => EntitlementHealthStatus::UNKNOWN,
            'enforcement_enabled' => AiCreditOperatingMode::isEnforced(),
        ];
    }
}
