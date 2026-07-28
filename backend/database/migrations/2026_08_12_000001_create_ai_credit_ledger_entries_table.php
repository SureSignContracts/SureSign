<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G4C.3A — the immutable AI Credit ledger foundation. Policy-agnostic:
 * every amount is supplied by the caller, already resolved — this table never
 * computes or resolves a charge itself. No CreditAccount/CreditReservation
 * table exists alongside it; balance AND reservation-lifecycle state are both
 * derived entirely from this one append-only table (see
 * App\Services\AI\AiCreditBalanceService / App\Services\AI\AiCreditLedgerService).
 *
 * Deliberately built with no workflow integration, no UI, and no policy
 * resolver — see internal-docs/commercial/ai-credit-policy-and-consumption-
 * model-v1.md Part Seven for the full G4C.3A scope boundary and the
 * readiness-gate reframing this phase's existence depends on (the gate
 * governs commercial activation, not this ledger's construction).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_credit_ledger_entries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // Null for org-wide grant/adjustment_*/expiry — only reserve/settle/
            // release (workflow-specific by nature) are expected to set this,
            // enforced by the service layer, not a DB constraint.
            $table->string('workflow', 50)->nullable();

            // grant | reserve | settle | release | adjustment_credit |
            // adjustment_debit | expiry | migration_credit | migration_debit
            // — see App\Support\AI\AiCreditTransactionType. Deliberately no
            // plain "adjustment"/"migration" — direction must be visible from
            // the transaction type itself, never a signed amount or a mutable
            // flag (amount is always positive).
            $table->string('transaction_type', 30);

            // Polymorphic — required in practice for reserve/settle/release
            // (the reservation's subject, e.g. a future ContractAiAnalysis
            // row), optional for grant/adjustment_*/expiry. Nullable at the
            // DB level because MySQL never treats two NULLs as equal, which
            // is exactly what lets the unique constraint below protect
            // reserve/settle/release without also limiting reference-less
            // grants to one each, ever.
            $table->nullableMorphs('reference');

            // Always positive; direction comes only from transaction_type.
            $table->decimal('amount', 10, 2);

            $table->text('reason');

            // 'user' | 'system' — who/what initiated this entry.
            $table->string('actor_type', 10);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            // Required on every write, no exceptions — the general-purpose
            // idempotency mechanism (grants/adjustments/expiries have no
            // natural reference to key off, so the reference-lifecycle
            // constraint below can't protect them).
            $table->string('idempotency_key')->unique();

            // No updated_at — see AiCreditLedgerEntry::UPDATED_AT = null.
            // Nothing may ever modify a row after insert.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['organization_id', 'created_at'], 'ai_credit_ledger_org_created_idx');
            $table->index(['organization_id', 'transaction_type'], 'ai_credit_ledger_org_type_idx');

            // The reservation-lifecycle invariant: at most one reserve, one
            // settle, and one release per (reference, transaction_type).
            // Effectively a no-op for reference-less rows (grant/adjustment_*/
            // expiry) since MySQL never matches NULL against NULL here.
            $table->unique(['reference_type', 'reference_id', 'transaction_type'], 'ai_credit_ledger_reference_lifecycle_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_credit_ledger_entries');
    }
};
