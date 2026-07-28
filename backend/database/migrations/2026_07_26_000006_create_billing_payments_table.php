<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('billing_invoices')->nullOnDelete();

            $table->string('provider', 30);
            $table->string('provider_payment_intent_id')->nullable();
            $table->string('provider_charge_id')->nullable();
            $table->string('provider_checkout_session_id')->nullable();

            // Human-readable operator-facing reference, e.g. PAY-000001.
            $table->string('internal_reference')->unique();

            $table->string('status', 30); // pending|processing|succeeded|failed|cancelled|refunded|partially_refunded|disputed
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('amount_refunded')->default(0);

            $table->string('payment_method_type')->nullable();
            $table->string('failure_code')->nullable();
            $table->string('failure_message')->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();

            $table->json('metadata_json')->nullable();
            $table->json('provider_payload_json')->nullable();

            $table->timestamps();

            // Prevent duplicate provider records — a payment intent or charge
            // is recorded at most once per provider. MySQL unique indexes
            // permit multiple NULLs, so rows that don't yet have one of these
            // provider IDs are unaffected.
            $table->unique(['provider', 'provider_payment_intent_id'], 'billing_payments_provider_intent_unique');
            $table->unique(['provider', 'provider_charge_id'], 'billing_payments_provider_charge_unique');
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_payments');
    }
};
