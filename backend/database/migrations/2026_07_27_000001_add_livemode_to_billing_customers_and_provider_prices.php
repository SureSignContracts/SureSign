<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive gap fix identified while building PlanPriceMappingService/
     * BillingCustomerService: neither `billing_customers` nor
     * `pricing_plan_provider_prices` recorded whether a given provider
     * object was created in Stripe Test Mode or Live Mode — unlike
     * `billing_webhook_events`, which already has this (`livemode`,
     * populated from the event payload). Without it, a service resolving
     * "the active mapping for this plan" or "the billing customer for
     * this organisation" has no local way to refuse a mapping that was
     * created under a different Stripe mode than the one currently
     * configured — it would have to trust whatever row exists, or make an
     * extra live Stripe API round-trip on every read just to check.
     *
     * `livemode` is populated from the provider's own returned `livemode`
     * flag (Stripe includes this on every Customer/Product/Price object),
     * never guessed locally.
     *
     * billing_customers' uniqueness is loosened from
     * (organization_id, provider) to (organization_id, provider, livemode)
     * — an organisation may legitimately accumulate a Test Mode customer
     * row from development/staging use and, separately, a Live Mode
     * customer row once real billing begins; these must not collide as
     * the same "slot", but exactly one row per organisation/provider/mode
     * is still enforced.
     */
    public function up(): void
    {
        Schema::table('billing_customers', function (Blueprint $table) {
            $table->boolean('livemode')->default(false)->after('provider_customer_id');
        });

        // The new unique index is created BEFORE the old one is dropped —
        // MySQL uses billing_customers_org_provider_unique to back the
        // organization_id foreign key (no other index on that column
        // exists), and refuses to drop it while it's the only index
        // supporting that constraint. Creating the replacement first always
        // leaves a valid supporting index in place.
        Schema::table('billing_customers', function (Blueprint $table) {
            $table->unique(['organization_id', 'provider', 'livemode'], 'billing_customers_org_provider_livemode_unique');
        });

        Schema::table('billing_customers', function (Blueprint $table) {
            $table->dropUnique('billing_customers_org_provider_unique');
        });

        Schema::table('pricing_plan_provider_prices', function (Blueprint $table) {
            $table->boolean('livemode')->default(false)->after('provider_price_id');
        });

        // Same ordering constraint as billing_customers above:
        // pricing_plan_provider_prices_lookup_idx is the only index
        // backing the pricing_plan_id foreign key, so the replacement
        // index must exist before the old one is dropped.
        Schema::table('pricing_plan_provider_prices', function (Blueprint $table) {
            $table->index(
                ['pricing_plan_id', 'billing_interval', 'currency', 'is_active', 'livemode'],
                'pricing_plan_provider_prices_lookup_livemode_idx'
            );
        });

        Schema::table('pricing_plan_provider_prices', function (Blueprint $table) {
            $table->dropIndex('pricing_plan_provider_prices_lookup_idx');
            $table->renameIndex('pricing_plan_provider_prices_lookup_livemode_idx', 'pricing_plan_provider_prices_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::table('pricing_plan_provider_prices', function (Blueprint $table) {
            $table->index(
                ['pricing_plan_id', 'billing_interval', 'currency', 'is_active'],
                'pricing_plan_provider_prices_lookup_idx_old'
            );
        });
        Schema::table('pricing_plan_provider_prices', function (Blueprint $table) {
            $table->dropIndex('pricing_plan_provider_prices_lookup_idx');
            $table->renameIndex('pricing_plan_provider_prices_lookup_idx_old', 'pricing_plan_provider_prices_lookup_idx');
            $table->dropColumn('livemode');
        });

        Schema::table('billing_customers', function (Blueprint $table) {
            $table->unique(['organization_id', 'provider'], 'billing_customers_org_provider_unique');
        });
        Schema::table('billing_customers', function (Blueprint $table) {
            $table->dropUnique('billing_customers_org_provider_livemode_unique');
            $table->dropColumn('livemode');
        });
    }
};
