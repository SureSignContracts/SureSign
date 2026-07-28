<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The authoritative SureSign subscription attached to an organisation.
     * Money columns (unit_amount/subtotal_amount/tax_amount/total_amount)
     * are integer MINOR units (e.g. 2999 = £29.99) — deliberately not the
     * decimal(10,2) MAJOR-unit convention pricing_plans uses, because these
     * values are provider-facing (Stripe's API is minor-unit only) whereas
     * pricing_plans' decimals are display-only. See
     * App\Support\Billing\Money for the conversion boundary between the two.
     *
     * Enforcing "only one primary pending/active subscription per
     * organisation" cannot be done with a MySQL conditional unique index
     * (no partial indexes) — see App\Services\Billing\SubscriptionService,
     * which enforces it with a row lock inside a transaction instead. The
     * (organization_id, status) index below only supports that check's
     * query pattern; it is not itself the enforcement mechanism.
     */
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pricing_plan_id')->nullable()->constrained('pricing_plans')->nullOnDelete();
            $table->foreignId('billing_customer_id')->nullable()->constrained('billing_customers')->nullOnDelete();

            $table->string('provider', 30);
            $table->string('provider_subscription_id')->nullable();
            $table->string('provider_checkout_session_id')->nullable();
            $table->string('provider_price_id')->nullable();

            // Human-readable operator-facing reference, e.g. SUB-000001 — see
            // App\Services\Billing\BillingReferenceService.
            $table->string('internal_reference')->unique();

            // See App\Support\Billing\SubscriptionStatus for the full,
            // documented set and App\Support\Billing\SubscriptionStatusMapper
            // for how provider statuses map onto it.
            $table->string('status', 30);

            $table->string('billing_interval', 20)->nullable(); // 'monthly' | 'annual'
            $table->char('currency', 3);

            $table->unsignedBigInteger('unit_amount')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('subtotal_amount')->nullable();
            $table->unsignedBigInteger('tax_amount')->nullable();
            $table->unsignedBigInteger('total_amount')->nullable();

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_starts_at')->nullable();
            $table->timestamp('current_period_ends_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('grace_period_ends_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason')->nullable();

            // Historical commercial snapshot — frozen at subscription
            // creation/plan-assignment time so a later edit to the public
            // pricing_plans row never silently reprices an existing
            // subscriber. subtotal/tax/total_amount above are themselves
            // already part of that snapshot; plan_code_snapshot/
            // plan_name_snapshot/commercial_terms_json capture the rest.
            $table->string('plan_code_snapshot')->nullable();
            $table->string('plan_name_snapshot')->nullable();
            $table->json('commercial_terms_json')->nullable();

            $table->json('metadata_json')->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
            $table->index('provider_subscription_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
