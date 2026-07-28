<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Maps a pricing_plans row to one or more provider Price objects.
     * Additive-only relationship onto the completed Pricing Management
     * schema — no existing pricing_plans column is touched.
     *
     * Stripe Prices are immutable for amount/currency, so a plan may
     * accumulate several rows over time (one per billing_interval/currency,
     * plus historical ones once a price changes) — is_active/
     * effective_from/effective_until track which mapping new checkouts
     * should use without deleting the historical ones existing
     * subscriptions still reference by provider_price_id.
     */
    public function up(): void
    {
        Schema::create('pricing_plan_provider_prices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('pricing_plan_id')->constrained('pricing_plans')->cascadeOnDelete();

            $table->string('provider', 30);
            $table->string('billing_interval', 20); // 'monthly' | 'annual'
            $table->char('currency', 3);

            $table->string('provider_product_id')->nullable();
            $table->string('provider_price_id')->nullable();

            $table->unsignedBigInteger('unit_amount'); // minor units

            $table->boolean('is_active')->default(true);
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_until')->nullable();

            $table->timestamps();

            $table->unique(['provider', 'provider_price_id'], 'pricing_plan_provider_prices_provider_price_unique');
            $table->index(['pricing_plan_id', 'billing_interval', 'currency', 'is_active'], 'pricing_plan_provider_prices_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_plan_provider_prices');
    }
};
