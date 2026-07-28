<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stripe Test Mode Integration, Provider Synchronisation & End-to-End
     * Billing Validation checkpoint — Part 9/10's dedicated plan-change
     * request record. One row per requested upgrade/downgrade, covering
     * both the domain state machine (requested → sent → confirmed →
     * applied, or cancelled/superseded/failed) AND the provider-operation
     * idempotency tracking Part 4 asks for — deliberately ONE table, not
     * two: this is the only NEW outbound provider write this checkpoint
     * introduces (customer/checkout/price creation already have their own
     * adequate idempotency via existing correlation mechanisms — see
     * App\Services\Billing\SubscriptionPlanChangeService's class docblock
     * for the full reasoning on why a second, generic "provider
     * operations" table was deliberately not built).
     *
     * A subscription may have AT MOST one non-terminal (`requested`/
     * `sent`/`confirmed`) row at a time — enforced in application code
     * under a row lock (`SubscriptionPlanChangeService`), the same pattern
     * `SubscriptionLifecycleService::hasConflictingSubscription()` already
     * uses for "one live subscription per organisation" (no MySQL partial
     * unique index is possible here either). Multiple terminal
     * (`applied`/`cancelled`/`superseded`/`failed`) rows persist
     * indefinitely as an audit trail — never deleted, never updated after
     * reaching a terminal state.
     *
     * `idempotency_key` is deterministic and stable per row
     * (`plan_change:{id}`, assigned once after the row is created — see
     * the service) — every retry of sending the outbound Stripe update for
     * THIS row reuses the same key, so a retried/duplicated send can never
     * cause Stripe to apply the same Price change twice.
     */
    public function up(): void
    {
        Schema::create('billing_plan_changes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->foreignId('source_pricing_plan_id')->nullable()->constrained('pricing_plans')->nullOnDelete();
            $table->foreignId('target_pricing_plan_id')->constrained('pricing_plans')->cascadeOnDelete();
            $table->foreignId('target_price_mapping_id')->constrained('pricing_plan_provider_prices')->cascadeOnDelete();

            // 'upgrade' | 'downgrade' — see App\Support\Billing\PlanChangeType.
            $table->string('change_type', 20);

            // 'immediate' | 'scheduled' — see App\Support\Billing\PlanChangePolicy.
            $table->string('policy', 30);

            $table->boolean('livemode')->default(false);

            // The state machine — see App\Support\Billing\PlanChangeState.
            $table->string('state', 30);

            $table->timestamp('requested_effective_at');
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at');

            // The idempotency boundary for the outbound Stripe subscription
            // update — see class docblock above.
            $table->string('idempotency_key')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('provider_confirmed_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('superseded_at')->nullable();

            $table->string('failure_code')->nullable();
            $table->string('failure_message')->nullable();

            $table->json('metadata_json')->nullable();

            $table->timestamps();

            $table->unique('idempotency_key');
            $table->index(['subscription_id', 'state']);
            $table->index(['state', 'requested_effective_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_plan_changes');
    }
};
