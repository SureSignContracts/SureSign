<?php

namespace App\Support\AI;

use App\Models\ContractAiAnalysis;
use App\Models\TradePackageAiAnalysis;

/**
 * Phase G4C.2C-1 — explicit, hand-whitelisted array shaping for every AI
 * analysis model that reaches an API response, mirroring the exact
 * discipline `App\Support\Billing\BillingPresenter` already established
 * for billing models carrying fields that must never round-trip to the
 * frontend verbatim. This codebase deliberately has no app/Http/Resources
 * layer (see that class's own docblock) — this stays plain static methods
 * for the same reason.
 *
 * Deliberately TWO separate methods per model, not one method with a
 * boolean/role flag: a single conditionally-exposing method is exactly
 * the pattern this phase was asked to avoid, since a future caller could
 * pass the wrong flag and silently leak execution telemetry. There is no
 * shared "build everything, then strip some" helper either — each method
 * lists its own fields explicitly, so removing a field from the internal
 * method can never accidentally remove it from the customer-facing one
 * or vice versa, and adding a new column to the model never appears in
 * either output until someone deliberately adds it here.
 *
 * Customer-facing methods expose business information and workflow
 * outcomes only — never provider/model identifiers, token counts,
 * duration, retry/queue metadata, stop reasons, provider-called flags,
 * internal hashes, or pricing metadata, even where a field isn't
 * "commercially sensitive" on its own. The rule is not "hide what's
 * sensitive" — it's "customer output never contains execution telemetry,
 * full stop." Internal methods are for Super Admin / authorised
 * platform-reporting callers only — controllers must enforce that
 * authorisation themselves before calling an `internal*()` method here;
 * this class has no authorisation logic of its own.
 */
class AiAnalysisPresenter
{
    // ─── Contract Analysis ──────────────────────────────────────────────

    public static function customerFacingContractAnalysis(ContractAiAnalysis $analysis): array
    {
        return [
            'id' => $analysis->id,
            'contract_id' => $analysis->contract_id,
            'project_id' => $analysis->project_id,
            'status' => $analysis->status,
            'progress_percent' => $analysis->progress_percent,
            'progress_stage' => $analysis->progress_stage,
            'progress_message' => $analysis->progress_message,
            'progress_updated_at' => $analysis->progress_updated_at,
            'summary' => $analysis->summary,
            'raw_response_json' => $analysis->raw_response_json,
            'confirmed_data_json' => $analysis->confirmed_data_json,
            'error_message' => $analysis->error_message,
            'started_at' => $analysis->started_at,
            'created_at' => $analysis->created_at,
            'completed_at' => $analysis->completed_at,
            'creator' => self::creator($analysis->creator),
            'contract' => self::contractRef($analysis->contract),
            // Deliberately omitted: provider, model, workflow, document_hash,
            // document_char_count, document_file_type, provider_called,
            // tokens_input, tokens_output, estimated_cost, stop_reason,
            // raw_response_text, duration_ms, queue_attempt, is_final_attempt,
            // failure_category — all execution telemetry, internal-only
            // regardless of sensitivity. started_at/completed_at are kept —
            // they're workflow timing (when did this run), not execution
            // internals.
        ];
    }

    public static function internalContractAnalysis(ContractAiAnalysis $analysis): array
    {
        return [
            'id' => $analysis->id,
            'contract_id' => $analysis->contract_id,
            'organization_id' => $analysis->organization_id,
            'project_id' => $analysis->project_id,
            'file_upload_id' => $analysis->file_upload_id,
            'status' => $analysis->status,
            'progress_percent' => $analysis->progress_percent,
            'progress_stage' => $analysis->progress_stage,
            'progress_message' => $analysis->progress_message,
            'progress_updated_at' => $analysis->progress_updated_at,
            'workflow' => $analysis->workflow,
            'telemetry_schema_version' => $analysis->telemetry_schema_version,
            'provider' => $analysis->provider,
            'model' => $analysis->model,
            'document_hash' => $analysis->document_hash,
            'document_char_count' => $analysis->document_char_count,
            'document_file_type' => $analysis->document_file_type,
            'summary' => $analysis->summary,
            'raw_response_json' => $analysis->raw_response_json,
            'raw_response_text' => $analysis->raw_response_text,
            'stop_reason' => $analysis->stop_reason,
            'provider_called' => $analysis->provider_called,
            'confirmed_data_json' => $analysis->confirmed_data_json,
            'error_message' => $analysis->error_message,
            'failure_category' => $analysis->failure_category,
            'tokens_input' => $analysis->tokens_input,
            'tokens_output' => $analysis->tokens_output,
            'estimated_cost' => $analysis->estimated_cost,
            'started_at' => $analysis->started_at,
            'completed_at' => $analysis->completed_at,
            'duration_ms' => $analysis->duration_ms,
            'queue_attempt' => $analysis->queue_attempt,
            'is_final_attempt' => $analysis->is_final_attempt,
            'created_at' => $analysis->created_at,
            'created_by' => $analysis->created_by,
            'creator' => self::creator($analysis->creator),
            'contract' => self::contractRef($analysis->contract),
        ];
    }

    // ─── Trade Package Analysis ─────────────────────────────────────────

    public static function customerFacingTradePackageAnalysis(TradePackageAiAnalysis $analysis): array
    {
        return [
            'id' => $analysis->id,
            'trade_package_id' => $analysis->trade_package_id,
            'project_id' => $analysis->project_id,
            'status' => $analysis->status,
            'progress_percent' => $analysis->progress_percent,
            'progress_stage' => $analysis->progress_stage,
            'progress_message' => $analysis->progress_message,
            'progress_updated_at' => $analysis->progress_updated_at,
            'summary' => $analysis->summary,
            'raw_response_json' => $analysis->raw_response_json,
            'confirmed_data_json' => $analysis->confirmed_data_json,
            'error_message' => $analysis->error_message,
            'started_at' => $analysis->started_at,
            'created_at' => $analysis->created_at,
            'completed_at' => $analysis->completed_at,
            'confirmed_at' => $analysis->confirmed_at,
            'cancelled_at' => $analysis->cancelled_at,
            'creator' => self::creator($analysis->creator),
            'trade_package' => self::tradePackageRef($analysis->tradePackage),
            // Same deliberate omission list as customerFacingContractAnalysis().
        ];
    }

    public static function internalTradePackageAnalysis(TradePackageAiAnalysis $analysis): array
    {
        return [
            'id' => $analysis->id,
            'trade_package_id' => $analysis->trade_package_id,
            'organization_id' => $analysis->organization_id,
            'project_id' => $analysis->project_id,
            'file_upload_id' => $analysis->file_upload_id,
            'status' => $analysis->status,
            'progress_percent' => $analysis->progress_percent,
            'progress_stage' => $analysis->progress_stage,
            'progress_message' => $analysis->progress_message,
            'progress_updated_at' => $analysis->progress_updated_at,
            'workflow' => $analysis->workflow,
            'telemetry_schema_version' => $analysis->telemetry_schema_version,
            'provider' => $analysis->provider,
            'model' => $analysis->model,
            'document_hash' => $analysis->document_hash,
            'document_char_count' => $analysis->document_char_count,
            'document_file_type' => $analysis->document_file_type,
            'summary' => $analysis->summary,
            'raw_response_json' => $analysis->raw_response_json,
            'raw_response_text' => $analysis->raw_response_text,
            'stop_reason' => $analysis->stop_reason,
            'provider_called' => $analysis->provider_called,
            'confirmed_data_json' => $analysis->confirmed_data_json,
            'error_message' => $analysis->error_message,
            'failure_category' => $analysis->failure_category,
            'tokens_input' => $analysis->tokens_input,
            'tokens_output' => $analysis->tokens_output,
            'estimated_cost' => $analysis->estimated_cost,
            'started_at' => $analysis->started_at,
            'completed_at' => $analysis->completed_at,
            'duration_ms' => $analysis->duration_ms,
            'queue_attempt' => $analysis->queue_attempt,
            'is_final_attempt' => $analysis->is_final_attempt,
            'confirmed_at' => $analysis->confirmed_at,
            'cancelled_at' => $analysis->cancelled_at,
            'created_at' => $analysis->created_at,
            'created_by' => $analysis->created_by,
            'creator' => self::creator($analysis->creator),
            'trade_package' => self::tradePackageRef($analysis->tradePackage),
        ];
    }

    // ─── Shared relation shaping (customer-safe either way) ─────────────

    private static function creator($creator): ?array
    {
        if ($creator === null) {
            return null;
        }

        return ['id' => $creator->id, 'name' => $creator->name, 'email' => $creator->email];
    }

    private static function contractRef($contract): ?array
    {
        if ($contract === null) {
            return null;
        }

        return ['id' => $contract->id, 'title' => $contract->title];
    }

    private static function tradePackageRef($tradePackage): ?array
    {
        if ($tradePackage === null) {
            return null;
        }

        return ['id' => $tradePackage->id, 'name' => $tradePackage->name];
    }
}
