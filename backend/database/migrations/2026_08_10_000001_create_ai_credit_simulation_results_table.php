<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G4C.2C-2 — non-enforcing AI Credit simulation storage.
 *
 * Deliberately NOT a ledger table, and named so it can never be mistaken
 * for one: no balance, no debit/credit sign, no reservation/settlement
 * state, no financial meaning whatsoever. A row here is purely "what would
 * candidate policy X, version Y have produced for this already-completed
 * analysis" — informational, recalculable, and safe to delete/rebuild
 * entirely without any accounting consequence. See
 * App\Models\AiCreditSimulationResult and App\Services\AI\AiCreditSimulator.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_credit_simulation_results', function (Blueprint $table) {
            $table->id();

            // Polymorphic — deliberately, so a future third provider-backed
            // workflow (with its own analysis table) needs no schema change
            // here, only a new resolveAnalysable() case in AiCreditSimulator.
            // Tenant safety: organization_id below is always read directly
            // from the source analysis at write time, never trusted from
            // caller input — see AiCreditSimulator::recordCandidateResult().
            $table->nullableMorphs('analysable');

            // Denormalized for fast, join-free report filtering — always
            // copied from the source analysis, never independently settable.
            $table->string('workflow', 50);
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('candidate_policy_key', 50);
            $table->unsignedInteger('candidate_policy_version');
            $table->string('charging_strategy', 20); // flat | banded | unresolved
            $table->string('normalization_version', 20);

            // Null when input was unavailable (e.g. historical backfill
            // couldn't reconstruct source text) — never 0 standing in for
            // "unknown".
            $table->unsignedInteger('normalized_input_char_count')->nullable();

            $table->string('hypothetical_band', 30)->nullable();
            // Decimal, not integer — a future banded policy may reasonably
            // want fractional credits; null (not 0) when unresolved/unavailable.
            $table->decimal('hypothetical_credits', 10, 2)->nullable();

            // calculated | unresolved | unavailable | error — see
            // AiCreditSimulator::STATUS_* constants. Never silently treated
            // as zero credits by any reporting code.
            $table->string('simulation_status', 20);
            $table->text('resolution_reason')->nullable();

            // prospective | backfill — which path produced this row.
            $table->string('source', 20);

            $table->timestamp('calculated_at');
            $table->timestamps();

            // Idempotency key — recalculating the same (analysis, candidate,
            // policy version, normalization version) updates this row rather
            // than creating a duplicate. Shorter explicit name: MySQL's
            // auto-generated index name for this many columns would exceed
            // the 64-character limit (a real, previously-hit class of bug —
            // see internal-docs/super-admin/subscription-billing.md's
            // G4B.1A/B section).
            $table->unique(
                ['analysable_type', 'analysable_id', 'candidate_policy_key', 'candidate_policy_version', 'normalization_version'],
                'ai_credit_sim_results_idempotency_key'
            );

            $table->index(['organization_id', 'workflow'], 'ai_credit_sim_results_org_workflow_idx');
            $table->index(['candidate_policy_key', 'hypothetical_band'], 'ai_credit_sim_results_policy_band_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_credit_simulation_results');
    }
};
