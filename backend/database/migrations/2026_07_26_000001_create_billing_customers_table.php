<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_customers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // 'stripe' only for now — see App\Support\Billing\BillingProviders.
            $table->string('provider', 30);
            $table->string('provider_customer_id');

            $table->string('billing_email')->nullable();
            $table->string('billing_name')->nullable();
            $table->json('billing_address_json')->nullable();
            $table->string('tax_id')->nullable();
            $table->string('tax_status')->nullable();
            $table->char('currency', 3)->nullable(); // ISO 4217

            $table->json('metadata_json')->nullable();

            $table->timestamps();

            // One customer mapping per organisation per provider.
            $table->unique(['organization_id', 'provider'], 'billing_customers_org_provider_unique');
            // A given provider customer ID must map back to exactly one organisation.
            $table->unique(['provider', 'provider_customer_id'], 'billing_customers_provider_customer_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_customers');
    }
};
