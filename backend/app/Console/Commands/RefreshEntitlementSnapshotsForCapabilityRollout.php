<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\Entitlements\EntitlementSnapshotService;
use App\Services\Entitlements\PlanEntitlementRepository;
use App\Services\Entitlements\SubscriptionAccessPolicy;
use App\Support\Entitlements\Feature;
use App\Support\Entitlements\SubscriptionAccessMode;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * A deliberate, one-time, manually-triggered commercial-entitlement
 * rollout — generalised (Friday Pack Entitlement Snapshot Capability
 * Rollout phase) to accept ANY registered, non-dormant `Feature::*` key,
 * rather than being hard-coded to the single key
 * (`Feature::CUSTOM_BRANDED_SUBDOMAIN`) it originally shipped for during
 * the Organisation URL Branding customer self-service phase.
 *
 * Exists SOLELY because of a real architectural fact: `FeatureGate`
 * resolves an already-active subscription's entitlements from its
 * immutable `SubscriptionEntitlementSnapshot`, frozen at activation — a
 * brand-new entitlement key added to `pricing_plan_entitlements` today
 * does NOTHING for a subscription whose snapshot predates that key; it
 * silently resolves to "not entitled" (logged as a warning by
 * `FeatureGate`, not an error) until that subscription's next real
 * commercial event.
 *
 * ─── Why this is a SURGICAL merge, not a full re-snapshot ──────────────
 *
 * The `custom_branded_subdomain`-era version of this command rebuilt each
 * eligible subscription's ENTIRE `entitlements_json` payload from today's
 * live plan defaults (`EntitlementSnapshotService::buildEntitlementsPayload()`)
 * — an explicitly documented, approved trade-off at the time ("if any
 * OTHER plan entitlement has drifted... this rollout picks that up too").
 * The Friday Pack Entitlement Snapshot Capability Rollout phase's own
 * brief prohibits exactly that: a capability rollout must never silently
 * alter a grandfathered/drifted value for an UNRELATED key. This version
 * therefore uses `EntitlementSnapshotService::snapshotForCapabilityRollout()`
 * instead — it copies the subscription's CURRENT snapshot verbatim and
 * replaces only the ONE requested capability's entry; every other key is
 * carried over byte-for-byte. This is a strictly safer default than the
 * original full-recompute behaviour, so it now applies uniformly to any
 * capability run through this command, not just `friday_packs` — there is
 * no second, capability-specific snapshot-refresh mechanism anywhere in
 * this codebase.
 *
 * ─── Scope: what this command does NOT do ───────────────────────────────
 *
 *   - Never touches `subscription_overrides`/negotiated Enterprise
 *     entitlements — no such storage exists yet in this codebase
 *     (`EntitlementOverrideRepository`'s only binding,
 *     `NullEntitlementOverrideRepository`, always returns "no override").
 *     `FeatureGate` consults an override BEFORE ever reading a snapshot —
 *     a real future override implementation would remain completely
 *     unaffected by any snapshot this command writes, by construction.
 *   - Never manufactures a snapshot for a subscription that doesn't
 *     already have a CURRENT one. A subscription with no snapshot at all
 *     is either genuinely legacy (`SnapshotIntegrityClassifier` already
 *     falls back to live plan data for it — no gap to fix here) or a
 *     separate, pre-existing data-integrity gap that
 *     `billing:subscriptions:check-integrity --repair` exists to close —
 *     not this command's concern.
 *   - Never considers `TRIAL`/`NONE`/`RESTRICTED` access modes. `TRIAL`
 *     resolves from the hardcoded, deliberately out-of-scope
 *     `PlanEntitlements::trialProfile()` (Phase G0 §11), never a plan
 *     snapshot's plan-code-derived values; `NONE`/`RESTRICTED` never
 *     consult a snapshot at all (`FeatureGate` denies before reaching
 *     one). Only `FULL`/`GRACE` ever resolve a capability from a
 *     subscription's plan-default snapshot content.
 *   - Never accepts an arbitrary string — the `{capability}` argument
 *     must be a real, non-dormant `Feature::*` key
 *     (`Feature::isValid()`/`Feature::isDormant()`), rejected safely
 *     otherwise.
 *
 * Deliberately NEVER auto-run from a migration, seeder, scheduler, or
 * deployment entrypoint — see routes/console.php (this command is not
 * registered there). This is treated as a genuine, reviewable
 * commercial-entitlement rollout event, not routine infrastructure.
 */
class RefreshEntitlementSnapshotsForCapabilityRollout extends Command
{
    protected $signature = 'entitlements:refresh-capability-rollout
        {capability : The Feature::* key to roll onto existing subscription snapshots (e.g. friday_packs)}
        {--dry-run : Show what would change without writing anything}
        {--confirm : Required to actually mutate — without it, a non-dry-run invocation asks interactively}';

    protected $description = 'One-time, explicit rollout: merge a single named entitlement capability into existing active subscription snapshots that predate it, without altering any other snapshot content.';

    public function handle(
        SubscriptionAccessPolicy $accessPolicy,
        EntitlementSnapshotService $snapshotService,
        PlanEntitlementRepository $planEntitlements,
    ): int {
        $capability = (string) $this->argument('capability');

        if (! Feature::isValid($capability)) {
            $this->error("\"{$capability}\" is not a registered Feature::* entitlement key. Refusing to roll out an unregistered capability.");

            return self::FAILURE;
        }

        if (Feature::isDormant($capability)) {
            $this->error("\"{$capability}\" is a dormant entitlement key — it never resolves to a real value regardless of snapshot content, so there is nothing to roll out.");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! $this->option('confirm')) {
            if (! $this->confirm("This will create new successor entitlement snapshots for active subscriptions whose current snapshot is missing or out of date on \"{$capability}\" — every other entitlement in each snapshot is preserved unchanged. Continue?", false)) {
                $this->info('Aborted — no changes made.');

                return self::SUCCESS;
            }
        }

        $counts = [
            'scanned' => 0,
            'eligible' => 0,
            'refreshed' => 0,
            'already_current' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];
        $effectiveFrom = CarbonImmutable::now();

        Subscription::query()
            ->chunkById(100, function ($subscriptions) use ($accessPolicy, $snapshotService, $planEntitlements, $capability, $dryRun, $effectiveFrom, &$counts) {
                foreach ($subscriptions as $subscription) {
                    $counts['scanned']++;

                    try {
                        $decision = $accessPolicy->resolve($subscription);

                        // Only a genuinely entitled-and-snapshot-backed mode
                        // is in scope — never NONE/RESTRICTED (never
                        // consult a snapshot at all) and never TRIAL (the
                        // hardcoded trial profile, deliberately out of
                        // scope — see class docblock).
                        if (! in_array($decision->mode, [SubscriptionAccessMode::FULL, SubscriptionAccessMode::GRACE], true)) {
                            $counts['skipped']++;

                            continue;
                        }

                        if ($subscription->plan_code_snapshot === null) {
                            $counts['skipped']++;

                            continue;
                        }

                        $currentSnapshot = $subscription->currentEntitlementSnapshot;

                        // No existing snapshot at all — never manufacture
                        // one here (see class docblock: either genuinely
                        // legacy and already correctly falling back live,
                        // or a separate integrity gap for
                        // billing:subscriptions:check-integrity to close).
                        if ($currentSnapshot === null) {
                            $counts['skipped']++;

                            continue;
                        }

                        $planValues = $planEntitlements->forPlanCode($subscription->plan_code_snapshot);
                        $resolvedValue = $planValues[$capability] ?? null;

                        // The plan itself has nothing to say about this
                        // capability (a genuinely unresolvable state, not
                        // simply "not included") — never guess, skip and
                        // let it surface for manual investigation instead.
                        if ($resolvedValue === null) {
                            $counts['skipped']++;

                            continue;
                        }

                        $newEntry = [
                            'value_type' => $resolvedValue->valueType,
                            'value' => $resolvedValue->value,
                            'is_unlimited' => $resolvedValue->isUnlimited,
                            'unit' => $resolvedValue->unit,
                            'source' => $resolvedValue->source,
                        ];

                        $counts['eligible']++;

                        $existingEntitlementsJson = $currentSnapshot->entitlements_json;
                        $currentEntry = $existingEntitlementsJson[$capability] ?? null;

                        if ($this->entryMatches($currentEntry, $newEntry)) {
                            $counts['already_current']++;

                            continue;
                        }

                        $counts['refreshed']++;

                        if (! $dryRun) {
                            $snapshotService->snapshotForCapabilityRollout(
                                $subscription,
                                $capability,
                                $existingEntitlementsJson,
                                $newEntry,
                                $effectiveFrom,
                            );
                        }
                    } catch (\Throwable $e) {
                        $counts['failed']++;
                        $this->error("Failed for subscription {$subscription->id}: {$e->getMessage()}");
                    }
                }
            });

        $this->table(
            ['Scanned', 'Eligible', $dryRun ? 'Would refresh' : 'Refreshed', 'Already current', 'Skipped', 'Failed'],
            [[$counts['scanned'], $counts['eligible'], $counts['refreshed'], $counts['already_current'], $counts['skipped'], $counts['failed']]],
        );

        if ($dryRun) {
            $this->info("Dry run only — nothing was written. Re-run without --dry-run (and with --confirm, or interactively) to apply \"{$capability}\".");
        }

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Field-by-field, key-order-independent comparison — a plain `===`
     * on the two arrays would be sensitive to hash-key ORDER (PHP's array
     * equality-with-order operator), which is not a real semantic
     * difference here and must never cause unnecessary snapshot churn on
     * a repeated run.
     *
     * @param  array{value_type: string, value: mixed, is_unlimited: bool, unit: ?string, source: string}|null  $current
     * @param  array{value_type: string, value: mixed, is_unlimited: bool, unit: ?string, source: string}  $new
     */
    private function entryMatches(?array $current, array $new): bool
    {
        if ($current === null) {
            return false;
        }

        foreach (['value_type', 'value', 'is_unlimited', 'unit'] as $field) {
            if (($current[$field] ?? null) !== ($new[$field] ?? null)) {
                return false;
            }
        }

        // 'source' is deliberately excluded from this comparison — it
        // only ever records HOW a value was resolved (plan default vs.
        // trial vs. override), not a customer-facing entitlement fact.
        // Two entries with an identical value_type/value/is_unlimited/unit
        // are already-current regardless of a cosmetic source label
        // difference — never manufacture churn over that alone.
        return true;
    }
}
