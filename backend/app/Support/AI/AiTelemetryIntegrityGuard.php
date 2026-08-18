<?php

namespace App\Support\AI;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase G4C.2C-2 — the smallest safe protection for the principle "once an
 * AI execution has reached a terminal state, its provider execution
 * telemetry should be treated as immutable historical evidence."
 *
 * Deliberately NOT a full immutable-value-object or event-sourcing
 * rebuild of ContractAiAnalysis/TradePackageAiAnalysis — both models
 * still hold ordinary, mutable business/workflow fields (status
 * transitions like completed → confirmed → cancelled, confirmed_data_json,
 * summary, error_message via reparse) that legitimately change after
 * "completion" in the everyday sense. Only a named list of execution
 * telemetry columns is protected, and only once the row's *previous*
 * status was already terminal — a save that transitions a row INTO a
 * terminal status for the first time (pending/processing → completed/
 * failed) is exactly how these fields are meant to be written, and is
 * never blocked.
 *
 * If a genuine need for broader immutability (e.g. a dedicated append-only
 * telemetry table) ever emerges, that's a deliberate future decision — not
 * introduced speculatively here.
 */
class AiTelemetryIntegrityGuard
{
    private const TERMINAL_STATUSES = ['completed', 'confirmed', 'failed', 'cancelled'];

    /**
     * Execution telemetry — facts about what actually happened when the
     * provider was (or wasn't) called. Never includes business/workflow
     * fields (status, summary, confirmed_data_json, error_message,
     * raw_response_json/text, started_at/completed_at) — those legitimately
     * change after a row first reaches a terminal status.
     */
    public const PROTECTED_FIELDS = [
        'provider',
        'model',
        'workflow',
        'telemetry_schema_version',
        'document_hash',
        'document_char_count',
        'document_file_type',
        'tokens_input',
        'tokens_output',
        'estimated_cost',
        'stop_reason',
        'provider_called',
        'duration_ms',
        'queue_attempt',
        'is_final_attempt',
        'failure_category',
        // Phase G4C.3BC — facts about the shadow credit reservation decided
        // at execution time; never rewritten once recorded, exactly like
        // every other field in this list.
        'credit_reservation_amount',
        'shadow_enforcement_result',
        // progress_percent/progress_stage/progress_message/progress_updated_at
        // are deliberately NOT in this list — they're live in-flight UI state
        // (what a pending/processing row should currently show a user), not
        // a fact about a completed provider execution. They stay mutable
        // even on a terminal row on purpose: e.g. the failure catch block in
        // AnalyseContractWithAiJob/AnalyseTradePackageWithAiJob still writes
        // progress_stage/progress_message ('failed') in the very same
        // update() that sets status to 'failed' for the first time — normal,
        // not a violation — and nothing else should ever need to touch them
        // again after that.
    ];

    /**
     * Call from a model's `updating` event. Throws AiTelemetryImmutableException
     * if the model's status was already terminal before this save AND a
     * protected field is being changed. A no-op for a fresh terminal
     * transition (original status still pending/processing) or for any
     * change that doesn't touch a protected field.
     */
    public static function assertMutable(Model $model): void
    {
        $originalStatus = $model->getOriginal('status');

        if (!in_array($originalStatus, self::TERMINAL_STATUSES, true)) {
            return;
        }

        foreach (self::PROTECTED_FIELDS as $field) {
            if ($model->isDirty($field)) {
                throw new AiTelemetryImmutableException($model, $field);
            }
        }
    }
}
