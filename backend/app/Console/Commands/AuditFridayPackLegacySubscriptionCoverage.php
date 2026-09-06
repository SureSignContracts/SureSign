<?php

namespace App\Console\Commands;

use App\Models\FridayPack;
use App\Models\Organization;
use App\Models\Subscription;
use App\Services\Entitlements\SubscriptionAccessPolicy;
use App\Support\Billing\SubscriptionStatus;
use App\Support\Entitlements\PlanEntitlements;
use App\Support\Entitlements\SubscriptionAccessMode;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Friday Pack Plan Entitlement Enforcement — Deployment B blocker
 * investigation. READ-ONLY. No table is written to, no subscription is
 * created, no entitlement default is changed, no FeatureGate/
 * SubscriptionAccessPolicy/EnsureFeatureIsEntitled behaviour is touched.
 *
 * Exists solely to answer, with real data, the question Deployment B's
 * regression run surfaced: do real customer organisations with genuine
 * Friday Pack history exist in SubscriptionAccessMode::NONE (no
 * qualifying subscription) today? Deliberately does NOT decide anything
 * — it only reports, using the exact same resolution `FeatureGate`
 * itself uses (`Organization::liveSubscription() ?? subscriptions()
 * ->latest('id')->first()`, then `SubscriptionAccessPolicy::resolve()`),
 * never a re-derived approximation of that logic.
 *
 * `NONE` retains its existing meaning throughout — this command never
 * treats it as equivalent to Essential, never grants it entitlement, and
 * recommends no automatic remediation. Any legacy-organisation cleanup
 * this report's findings imply is a separate, deliberate, manually
 * reviewed action — not something this command performs.
 */
class AuditFridayPackLegacySubscriptionCoverage extends Command
{
    protected $signature = 'friday-packs:audit-legacy-subscription-coverage';

    protected $description = 'Read-only audit: which organisations with Friday Pack history have no qualifying subscription (SubscriptionAccessMode::NONE). Writes nothing.';

    public function handle(SubscriptionAccessPolicy $accessPolicy): int
    {
        $this->info('Friday Pack Legacy Subscription Coverage Audit — READ ONLY, no writes.');
        $this->newLine();

        // ── D. No-subscription organisations generally ──────────────────
        //
        // No `is_platform`/`type`/internal-entity distinction exists on
        // App\Models\Organization today (confirmed by reading its
        // fillable/cast list directly) — this audit therefore treats
        // every non-soft-deleted Organization row as a "customer
        // organisation". This is stated explicitly rather than silently
        // assumed, since it materially affects every count below.
        $organizations = Organization::query()->whereNull('deleted_at')->get(['id', 'name', 'is_active', 'created_at']);
        $totalOrganizations = $organizations->count();

        $classifications = [];
        foreach ($organizations as $org) {
            $subscription = $this->resolveSubscription($org);
            $decision = $accessPolicy->resolve($subscription);
            $planCategory = $this->planCategory($decision, $subscription);

            $classifications[$org->id] = [
                'organization' => $org,
                'subscription' => $subscription,
                'mode' => $decision->mode,
                'status' => $decision->subscriptionStatus,
                'reason_code' => $decision->reasonCode,
                'plan_category' => $planCategory,
            ];
        }

        $qualifying = collect($classifications)->filter(fn ($c) => in_array($c['mode'], [SubscriptionAccessMode::FULL, SubscriptionAccessMode::GRACE, SubscriptionAccessMode::TRIAL], true));
        $none = collect($classifications)->filter(fn ($c) => $c['mode'] === SubscriptionAccessMode::NONE);
        $restricted = collect($classifications)->filter(fn ($c) => $c['mode'] === SubscriptionAccessMode::RESTRICTED);

        $this->line('── D. Organisation-wide subscription coverage ──');
        $this->table(
            ['Total organisations', 'Qualifying (FULL/GRACE/TRIAL)', 'RESTRICTED', 'NONE'],
            [[$totalOrganizations, $qualifying->count(), $restricted->count(), $none->count()]],
        );
        $this->newLine();

        // ── A/B. Friday Pack history, overall and among NONE orgs ───────
        $orgIdsWithHistory = FridayPack::query()->distinct()->pluck('organization_id');
        $totalFridayPacks = FridayPack::count();
        $approvedOrSent = FridayPack::whereIn('status', ['approved', 'sent'])->count();
        $mostRecent = FridayPack::max('generated_at');
        $activeLast30 = FridayPack::where('generated_at', '>=', CarbonImmutable::now()->subDays(30))->distinct('organization_id')->count('organization_id');
        $activeLast90 = FridayPack::where('generated_at', '>=', CarbonImmutable::now()->subDays(90))->distinct('organization_id')->count('organization_id');

        $this->line('── A/B. Friday Pack history (platform-wide) ──');
        $this->table(
            ['Orgs with ≥1 Friday Pack', 'Total Friday Packs', 'Approved/Sent', 'Most recent generated_at', 'Orgs active last 30d', 'Orgs active last 90d'],
            [[$orgIdsWithHistory->count(), $totalFridayPacks, $approvedOrSent, $mostRecent ?? 'n/a', $activeLast30, $activeLast90]],
        );
        $this->newLine();

        // ── C. Subscription coverage among Friday-Pack-using orgs ───────
        $historyClassifications = collect($classifications)->only($orgIdsWithHistory->all());
        $byCategory = $historyClassifications->groupBy('plan_category')->map->count();

        $this->line('── C. Subscription coverage among organisations WITH Friday Pack history ──');
        $categories = [PlanEntitlements::ESSENTIAL, PlanEntitlements::PROFESSIONAL, PlanEntitlements::ENTERPRISE, 'trial', 'grace', 'restricted', 'none_unrecognised_plan', 'NONE'];
        $rows = [];
        foreach ($categories as $cat) {
            $rows[] = [$cat, $byCategory->get($cat, 0)];
        }
        $this->table(['Category', 'Organisations with Friday Pack history'], $rows);
        $this->newLine();

        // ── The critical question: NONE orgs with Friday Pack history ───
        $noneWithHistory = $historyClassifications->filter(fn ($c) => $c['mode'] === SubscriptionAccessMode::NONE);

        $this->line('── Decision-relevant detail: NONE organisations with Friday Pack history ──');
        if ($noneWithHistory->isEmpty()) {
            $this->info('CASE A — none found. No organisation with Friday Pack history is currently in SubscriptionAccessMode::NONE.');
        } else {
            $rows = [];
            foreach ($noneWithHistory as $orgId => $c) {
                $packCount = FridayPack::where('organization_id', $orgId)->count();
                $lastActivity = FridayPack::where('organization_id', $orgId)->max('generated_at');
                $recentActivity = FridayPack::where('organization_id', $orgId)
                    ->where('generated_at', '>=', CarbonImmutable::now()->subDays(90))
                    ->exists();

                $rows[] = [
                    $orgId,
                    $c['organization']->is_active ? 'yes' : 'no',
                    $c['subscription']?->status ?? 'no_subscription_row',
                    $c['reason_code'],
                    $packCount,
                    $lastActivity ?? 'n/a',
                    $recentActivity ? 'yes (<=90d)' : 'no',
                ];
            }
            $this->table(
                ['org_id', 'org.is_active', 'latest subscription status', 'reason_code', 'friday pack count', 'last generated_at', 'recent activity'],
                $rows,
            );

            $recentCount = $noneWithHistory->filter(function ($c, $orgId) {
                return FridayPack::where('organization_id', $orgId)
                    ->where('generated_at', '>=', CarbonImmutable::now()->subDays(90))
                    ->exists();
            })->count();

            if ($recentCount > 0) {
                $this->error("CASE C — {$recentCount} of {$noneWithHistory->count()} NONE organisation(s) with Friday Pack history show activity within the last 90 days. Deployment B remains BLOCKED — see reason_code column above for the likely cause per organisation (no_subscription / draft / pending_payment / incomplete / paused / unrecognised_status).");
            } else {
                $this->warn("CASE B — {$noneWithHistory->count()} NONE organisation(s) with Friday Pack history found, but none active in the last 90 days. Report for deliberate commercial/account cleanup before enforcement — do not auto-grant entitlement.");
            }
        }

        $this->newLine();
        $this->info('Audit complete. No data was written.');

        return self::SUCCESS;
    }

    /**
     * Mirrors FeatureGate::resolveSubscription() exactly — never a
     * re-derived approximation.
     */
    private function resolveSubscription(Organization $organization): ?Subscription
    {
        return $organization->liveSubscription ?? $organization->subscriptions()->latest('id')->first();
    }

    private function planCategory($decision, ?Subscription $subscription): string
    {
        if ($decision->mode === SubscriptionAccessMode::NONE) {
            return SubscriptionAccessMode::NONE;
        }

        if ($decision->mode === SubscriptionAccessMode::TRIAL) {
            return 'trial';
        }

        if ($decision->mode === SubscriptionAccessMode::GRACE) {
            return 'grace';
        }

        if ($decision->mode === SubscriptionAccessMode::RESTRICTED) {
            return 'restricted';
        }

        // FULL — classify by the subscription's own recorded plan code.
        $planCode = $subscription?->plan_code_snapshot;

        return match ($planCode) {
            PlanEntitlements::ESSENTIAL => PlanEntitlements::ESSENTIAL,
            PlanEntitlements::PROFESSIONAL => PlanEntitlements::PROFESSIONAL,
            PlanEntitlements::ENTERPRISE => PlanEntitlements::ENTERPRISE,
            default => 'none_unrecognised_plan',
        };
    }
}
