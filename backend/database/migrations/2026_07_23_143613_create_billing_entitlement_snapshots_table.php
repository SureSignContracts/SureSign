<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Immutable entitlement snapshot foundation (Subscription Commercial
     * State Automation checkpoint) — a frozen, point-in-time record of what
     * a subscription was entitled to at a specific commercial moment
     * (activation, upgrade/downgrade taking effect, trial start, an
     * Enterprise amendment). Rows are never updated after creation — see
     * App\Models\SubscriptionEntitlementSnapshot, which enforces this at
     * the model level. This closes the gap App\Support\Entitlements\
     * PlanEntitlements's own docblock documents: resolving live from
     * plan-code today means a future PlanEntitlements edit would
     * retroactively change what an existing subscription resolves to.
     *
     * The (subscription_id, source_transition, effective_from) unique
     * index is the idempotency boundary a retried/duplicated automation
     * run relies on — see App\Services\Entitlements\EntitlementSnapshotService.
     * It deliberately does NOT include plan_code_snapshot: two distinct
     * commercial events for the same subscription always differ in either
     * source_transition or effective_from (a renewal/upgrade/downgrade
     * always has a new effective instant), so this key can never block a
     * legitimate future snapshot while still rejecting an exact duplicate
     * of one already recorded.
     */
    public function up(): void
    {
        Schema::create('billing_entitlement_snapshots', function (Blueprint $table) {
            $table->id();

            // G4B.1B repair — this migration is timestamped BEFORE
            // 2026_07_26_000003_create_subscriptions_table.php, so an
            // inline `->constrained('subscriptions')` here fails on a
            // genuinely fresh MySQL install (error 1824: MySQL validates a
            // referenced table's existence at CREATE TABLE time). SQLite
            // does not validate this eagerly, which is why every prior
            // SQLite-based test run never caught it. The column is created
            // here for every driver; the FOREIGN KEY constraint itself is
            // added inline only for drivers that tolerate the forward
            // reference (SQLite), and deferred to
            // 2026_07_26_000011_add_deferred_foreign_keys_to_billing_entitlement_snapshots_table.php
            // for MySQL, once `subscriptions` actually exists. Any
            // environment where this migration already ran with the old
            // inline constraint (confirmed present in this project's own
            // local MySQL dev database) is unaffected — Laravel never
            // re-runs an already-recorded migration, and the new deferred
            // migration checks for the constraint before adding it.
            $subscriptionIdColumn = $table->foreignId('subscription_id');
            if (Schema::getConnection()->getDriverName() !== 'mysql') {
                $subscriptionIdColumn->constrained('subscriptions')->cascadeOnDelete();
            }

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // Same repair as subscription_id above — this migration also
            // predates 2026_07_25_000002_create_pricing_plans_table.php.
            $pricingPlanIdColumn = $table->foreignId('pricing_plan_id')->nullable();
            if (Schema::getConnection()->getDriverName() !== 'mysql') {
                $pricingPlanIdColumn->constrained('pricing_plans')->nullOnDelete();
            }

            // Frozen at snapshot time — never re-read from pricing_plans
            // after this row exists, matching the same grandfathering
            // convention subscriptions.plan_code_snapshot already uses.
            $table->string('plan_code_snapshot')->nullable();

            // The full resolved App\Support\Entitlements\EntitlementValue
            // set at this moment, keyed by Feature::* — see
            // EntitlementSnapshotService::buildEntitlementsPayload().
            $table->json('entitlements_json');

            // When this snapshot's entitlement set actually took/takes
            // commercial effect — may be in the future for a
            // scheduled-but-not-yet-applied event (none exist yet this
            // checkpoint; always "now" in practice today).
            $table->timestamp('effective_from');

            // Human-readable commercial reason — 'activation', 'trial_start',
            // 'upgrade_applied', 'downgrade_applied', 'enterprise_amendment'.
            $table->string('lifecycle_reason', 60);

            // The exact SubscriptionLifecycleService transition (or
            // ActivityLog action) that produced this snapshot — the other
            // half of the idempotency boundary alongside effective_from.
            $table->string('source_transition', 60);

            $table->timestamps();

            $table->unique(['subscription_id', 'source_transition', 'effective_from'], 'billing_entitlement_snapshots_idempotency');
            // G4B.1B repair — a third, distinct MySQL-only defect found in
            // this same file while validating the FK-ordering repair
            // above: Laravel's auto-generated name for this composite
            // index ("billing_entitlement_snapshots_subscription_id_effective_from_index",
            // 68 characters) exceeds MySQL's 64-character identifier limit
            // (error 1059) — invisible on SQLite, which has no such limit.
            // An explicit short name sidesteps this entirely; the index's
            // actual columns are unchanged.
            $table->index(['subscription_id', 'effective_from'], 'billing_entitlement_snapshots_sub_effective_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_entitlement_snapshots');
    }
};
