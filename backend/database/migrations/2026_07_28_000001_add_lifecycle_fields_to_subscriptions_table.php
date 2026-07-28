<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive gap fixes identified while building SubscriptionLifecycleService:
     *
     * 1. `livemode` — subscriptions had no local record of which Stripe mode
     *    they were created under, unlike billing_customers and
     *    pricing_plan_provider_prices (both fixed in an earlier checkpoint).
     *    Populated from the provider's own returned `livemode` flag, never
     *    guessed — see App\Services\Billing\SubscriptionLifecycleService's
     *    activation/reconciliation guards.
     *
     * 2. `last_transition_occurred_at` — required for stale-event rejection
     *    (an older provider event arriving after a newer one was already
     *    applied must never roll the subscription backward). Distinct from
     *    `updated_at`, which changes on ANY column touch, not specifically
     *    on a lifecycle transition — this column records only the
     *    `occurred_at` of the last transition SubscriptionLifecycleService
     *    actually applied, giving every future transition attempt something
     *    concrete to compare a new event's `occurred_at` against. This is
     *    foundation for the webhook checkpoint's stale-event handling, not
     *    a webhook event store itself — no such table is added here.
     *
     * 3. `pending_pricing_plan_id` / `pending_billing_interval` /
     *    `plan_change_effective_at` — there was no safe way to represent
     *    "this subscription is on Professional today but will move to
     *    Essential at renewal" without overwriting the CURRENT plan
     *    fields, which would destroy the historical commercial snapshot
     *    those fields exist to protect. A scheduled downgrade needs to
     *    coexist with the current plan until its effective date arrives —
     *    these three columns are the minimum needed to represent that,
     *    nothing more (no separate plan-change history table is added;
     *    ActivityLog already covers the history/audit trail, per Part 14).
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->boolean('livemode')->default(false)->after('provider_price_id');
            $table->timestamp('last_transition_occurred_at')->nullable()->after('updated_by_user_id');

            $table->foreignId('pending_pricing_plan_id')->nullable()->after('pricing_plan_id')
                ->constrained('pricing_plans')->nullOnDelete();
            $table->string('pending_billing_interval', 20)->nullable()->after('billing_interval');
            $table->timestamp('plan_change_effective_at')->nullable()->after('pending_billing_interval');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pending_pricing_plan_id');
            $table->dropColumn(['pending_billing_interval', 'plan_change_effective_at', 'last_transition_occurred_at', 'livemode']);
        });
    }
};
