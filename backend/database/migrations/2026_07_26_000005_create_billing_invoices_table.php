<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Local mirror of a Stripe invoice. provider_payload_json is the raw
     * provider response for support/debugging only — never serialize it in
     * an API response (see App\Support\Billing\BillingPresenter).
     */
    public function up(): void
    {
        Schema::create('billing_invoices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->foreignId('billing_customer_id')->nullable()->constrained('billing_customers')->nullOnDelete();

            $table->string('provider', 30);
            $table->string('provider_invoice_id');

            // Human-readable operator-facing reference, e.g. INV-000001.
            $table->string('invoice_number')->nullable();

            $table->string('status', 30); // draft | open | paid | void | uncollectible
            $table->char('currency', 3);

            $table->unsignedBigInteger('subtotal_amount')->nullable();
            $table->unsignedBigInteger('tax_amount')->nullable();
            $table->unsignedBigInteger('discount_amount')->nullable();
            $table->unsignedBigInteger('total_amount')->nullable();
            $table->unsignedBigInteger('amount_due')->nullable();
            $table->unsignedBigInteger('amount_paid')->nullable();
            $table->unsignedBigInteger('amount_remaining')->nullable();

            $table->string('hosted_invoice_url')->nullable();
            $table->string('invoice_pdf_url')->nullable();
            $table->string('billing_reason')->nullable();

            $table->timestamp('period_starts_at')->nullable();
            $table->timestamp('period_ends_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('voided_at')->nullable();

            $table->json('metadata_json')->nullable();
            $table->json('provider_payload_json')->nullable();

            $table->timestamps();

            $table->unique(['provider', 'provider_invoice_id'], 'billing_invoices_provider_invoice_unique');
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_invoices');
    }
};
