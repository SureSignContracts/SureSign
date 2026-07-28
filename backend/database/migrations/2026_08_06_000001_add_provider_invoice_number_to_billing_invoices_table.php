<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E3 finance-readiness review finding: `invoice_number` on this table
 * is a SureSign-internal correlation reference (via BillingReferenceService,
 * same pattern as SUB-000001/CHK-000001) — it was never Stripe's own actual
 * invoice number, the one that appears on the hosted invoice page and PDF a
 * customer/accountant actually sees. Both are legitimately useful for
 * different purposes; conflating them under one generic "Invoice" label
 * risked a real finance-reconciliation mismatch. This column stores
 * Stripe's own `invoice.number` verbatim (passthrough only, never
 * generated locally) — see InvoiceSyncService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table) {
            $table->string('provider_invoice_number')->nullable()->after('invoice_number');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table) {
            $table->dropColumn('provider_invoice_number');
        });
    }
};
