<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Manual credits, waivers, negotiated discounts, and write-offs. Purely
     * a ledger — recording an adjustment here has no automatic financial
     * effect until a later phase's service explicitly applies it.
     */
    public function up(): void
    {
        Schema::create('billing_adjustments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('billing_invoices')->nullOnDelete();

            $table->string('type', 30); // credit | waiver | discount | write_off
            $table->string('description');
            $table->char('currency', 3);
            $table->bigInteger('amount'); // minor units; signed — a credit/waiver reduces amount owed

            $table->timestamp('effective_at');
            $table->foreignId('created_by_user_id')->constrained('users');

            $table->json('metadata_json')->nullable();

            $table->timestamps();

            $table->index(['organization_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_adjustments');
    }
};
