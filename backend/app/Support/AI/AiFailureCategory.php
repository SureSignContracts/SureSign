<?php

namespace App\Support\AI;

/**
 * The single authoritative set of structured AI analysis failure categories
 * (Phase G4C.1). Written only to `failure_category` on
 * ContractAiAnalysis/TradePackageAiAnalysis when `status = 'failed'`, via
 * App\Services\AI\AiFailureClassifier — never assigned ad hoc elsewhere.
 *
 * CANCELLED is intentionally not classified here: a user-cancelled analysis
 * is already fully represented by `status = 'cancelled'` and never reaches
 * the failure path, so no exception is ever classified against it.
 */
final class AiFailureCategory
{
    public const VALIDATION_FAILURE = 'validation_failure';
    public const PROVIDER_REJECTION = 'provider_rejection';
    public const OUTPUT_TRUNCATED = 'output_truncated';
    public const TIMEOUT = 'timeout';
    public const TRANSPORT_ERROR = 'transport_error';
    public const INTERNAL_EXCEPTION = 'internal_exception';
    public const UNKNOWN = 'unknown';

    /**
     * Phase G4C.3I — the analysis was blocked before the provider was ever
     * called because AiCreditWorkflowLifecycle::shouldBlock() found a
     * resolved, insufficient balance with enforcement enabled. Distinct
     * from every other category here, which all describe the provider call
     * itself failing — this one means the provider was deliberately never
     * reached.
     */
    public const INSUFFICIENT_CREDITS = 'insufficient_credits';

    public const ALL = [
        self::VALIDATION_FAILURE,
        self::PROVIDER_REJECTION,
        self::OUTPUT_TRUNCATED,
        self::TIMEOUT,
        self::TRANSPORT_ERROR,
        self::INTERNAL_EXCEPTION,
        self::INSUFFICIENT_CREDITS,
        self::UNKNOWN,
    ];
}
