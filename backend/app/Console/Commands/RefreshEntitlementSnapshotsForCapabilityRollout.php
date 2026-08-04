<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\Entitlements\EntitlementSnapshotService;
use App\Services\Entitlements\SubscriptionAccessPolicy;
use App\Support\Entitlements\Feature;
use App\Support\Entitlements\SubscriptionAccessMode;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Organisation URL Branding, customer self-service phase — a deliberate,
 * one-time, manually-triggered commercial-entitlement rollout. Exists
 * SOLELY because of a real architectural fact: `FeatureGate` resolves an
 * already-active subscription's entitlements from its immutable
 * `SubscriptionEntitlementSnapshot`, frozen at activation — a brand-new
 * entitlement key (`Feature::CUSTOM_BRANDED_SUBDOMAIN`) added to
 * `pricing_plan_entitlements` today does NOTHING for a subscription whose
 * snapshot predates that key; it silently resolves to "not entitled"
 * (logged as a warning by FeatureGate, not an error) until that
 * subscription's next real commercial event.
 *
 * This command creates a NEW snapshot for every currently eligible
 * subscription via `EntitlementSnapshotService::snapshotForEntitlementRollout()`
 * — a deliberately distinct `source_transition` from every real
 * commercial-event snapshot (activation/trial/upgrade/downgrade/
 * amendment), so it is never confused with one of those in any report or
 * integrity check. The regenerated snapshot contains the FULL current
 * live plan-entitlement set (not a hand-merged single key) — this is
 * `EntitlementSnapshotService::buildEntitlementsPayload()`'s existing,
 * deterministic behaviour, reused unchanged; if any OTHER plan
 * entitlement has drifted in `pricing_plan_entitlements` since a given
 * subscription's original snapshot, this rollout picks that up too. This
 * was an explicit, approved trade-off for this rollout — see
 * internal-docs/super-admin/organisation-url-branding.md.
 *
 * Deliberately NEVER auto-run from a migration, seeder, scheduler, or
 * deployment entrypoint — see routes/console.php (this command is not
 * registered there) and the recommended production flow in this class's
 * own `--dry-run` output. This is treated as a genuine, reviewable
 * commercial-entitlement rollout event, not routine infrastructure.
 *
 * Explicitly does NOT touch `Feature::CUSTOM_DOMAIN` — only the one
 * approved key for this rollout.
 */
class RefreshEntitlementSnapshotsForCapabilityRollout extends Command
{
    protected $signature = 'entitlements:refresh-capability-rollout
        {--dry-run : Show what would change without writing anything}
        {--confirm : Required to actually mutate — without it, a non-dry-run invocation asks interactively}';

    protected $description = 'One-time, explicit rollout: refresh entitlement snapshots for active Professional/Enterprise subscriptions so they pick up Feature::CUSTOM_BRANDED_SUBDOMAIN.';

    private const ELIGIBLE_PLAN_CODES = ['professional', 'enterprise'];

    public function handle(SubscriptionAccessPolicy $accessPolicy, EntitlementSnapshotService $snapshotService): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! $this->option('confirm')) {
            if (! $this->confirm('This will create new entitlement snapshots for currently active Professional/Enterprise subscriptions. Continue?', false)) {
                $this->info('Aborted — no changes made.');

                return self::SUCCESS;
            }
        }

        $counts = ['eligible' => 0, 'would_change' => 0, 'unchanged' => 0, 'skipped' => 0, 'failed' => 0];
        $effectiveFrom = CarbonImmutable::now();

        Subscription::query()
            ->whereIn('plan_code_snapshot', self::ELIGIBLE_PLAN_CODES)
            ->chunkById(100, function ($subscriptions) use ($accessPolicy, $snapshotService, $dryRun, $effectiveFrom, &$counts) {
                foreach ($subscriptions as $subscription) {
                    try {
                        $decision = $accessPolicy->resolve($subscription);

                        // Only a genuinely entitled mode is eligible — never
                        // a cancelled/expired/suspended/unpaid/restricted
                        // subscription, and never NONE. TRIAL is
                        // deliberately excluded too: the trial profile is a
                        // fixed hardcoded set (PlanEntitlements::trialProfile()),
                        // entirely separate from plan defaults — this
                        // rollout is specifically about PAID plan
                        // entitlements, not trial behaviour.
                        if (! in_array($decision->mode, [SubscriptionAccessMode::FULL, SubscriptionAccessMode::GRACE], true)) {
                            $counts['skipped']++;

                            continue;
                        }

                        $counts['eligible']++;

                        $currentSnapshot = $subscription->currentEntitlementSnapshot;
                        $currentValue = $currentSnapshot?->entitlements_json[Feature::CUSTOM_BRANDED_SUBDOMAIN]['value'] ?? null;

                        $newPayload = $snapshotService->buildEntitlementsPayload($subscription, 'entitlement_rollout');
                        $newValue = $newPayload[Feature::CUSTOM_BRANDED_SUBDOMAIN]['value'] ?? null;

                        // Idempotency signal for reporting: if the CURRENT
                        // snapshot already resolves this key to the same
                        // value the rollout would produce, nothing
                        // meaningful changes (still safe/idempotent to
                        // re-run — createOrReuse() would just reuse
                        // today's row again if one already exists for this
                        // exact effective_from).
                        if ($currentValue === $newValue && $currentSnapshot !== null) {
                            $counts['unchanged']++;

                            continue;
                        }

                        $counts['would_change']++;

                        if (! $dryRun) {
                            $snapshotService->snapshotForEntitlementRollout($subscription, $effectiveFrom);
                        }
                    } catch (\Throwable $e) {
                        $counts['failed']++;
                        $this->error("Failed for subscription {$subscription->id}: {$e->getMessage()}");
                    }
                }
            });

        $this->table(['Eligible', 'Would change', 'Unchanged', 'Skipped', 'Failed'], [[
            $counts['eligible'], $counts['would_change'], $counts['unchanged'], $counts['skipped'], $counts['failed'],
        ]]);

        if ($dryRun) {
            $this->info('Dry run only — nothing was written. Re-run without --dry-run (and with --confirm, or interactively) to apply.');
        }

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
