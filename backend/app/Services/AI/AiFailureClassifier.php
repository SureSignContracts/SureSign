<?php

namespace App\Services\AI;

use App\Support\AI\AiCreditEnforcementException;
use App\Support\AI\AiFailureCategory;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * Normalizes an AI analysis job failure into one of the structured
 * App\Support\AI\AiFailureCategory values (Phase G4C.1). Classification is
 * based only on exception type and the already-curated, safe-to-display
 * message text this pipeline's own services/provider already produce (see
 * ContractAnalysisService/ClaudeAiProvider) — it never inspects raw provider
 * bodies, and it never changes what message is shown to the customer.
 *
 * This is the single authoritative classifier — both
 * AnalyseContractWithAiJob and AnalyseTradePackageWithAiJob call it rather
 * than each inventing their own categorisation.
 */
class AiFailureClassifier
{
    /** Messages ClaudeAiProvider/ContractAnalysisService throw for input the analysis pipeline itself rejected. */
    private const VALIDATION_MESSAGE_FRAGMENTS = [
        'not found in storage',
        'file not found',
        'is not supported for AI analysis',
        'appears to be empty or could not be read',
        'PDF text extraction is not available',
    ];

    /** Messages produced after a real, completed provider response that was unusable. */
    private const PROVIDER_REJECTION_MESSAGE_FRAGMENTS = [
        'AI request could not be completed',
        'AI service returned an unexpected response',
        'could not be read',
    ];

    /**
     * The response hit the configured output token ceiling and was cut off
     * mid-JSON (see ContractAnalysisService/TradePackageAnalysisService,
     * which set this message only when stop_reason === 'max_tokens') — a
     * distinct, precisely-detectable failure mode from a generic malformed
     * response, worth its own category so a future ledger/report can tell
     * "the ceiling was too low" apart from "the provider returned garbage".
     */
    private const OUTPUT_TRUNCATED_MESSAGE_FRAGMENTS = [
        'longer than the response limit and was cut off',
    ];

    public static function classify(Throwable $e): string
    {
        if ($e instanceof AiCreditEnforcementException) {
            return AiFailureCategory::INSUFFICIENT_CREDITS;
        }

        if ($e instanceof ConnectionException) {
            return str_contains(strtolower($e->getMessage()), 'timed out')
                ? AiFailureCategory::TIMEOUT
                : AiFailureCategory::TRANSPORT_ERROR;
        }

        if (!$e instanceof \RuntimeException) {
            return AiFailureCategory::INTERNAL_EXCEPTION;
        }

        $message = $e->getMessage();

        if (str_contains($message, 'AI analysis is not configured')) {
            return AiFailureCategory::INTERNAL_EXCEPTION;
        }

        foreach (self::VALIDATION_MESSAGE_FRAGMENTS as $fragment) {
            if (str_contains($message, $fragment)) {
                return AiFailureCategory::VALIDATION_FAILURE;
            }
        }

        foreach (self::OUTPUT_TRUNCATED_MESSAGE_FRAGMENTS as $fragment) {
            if (str_contains($message, $fragment)) {
                return AiFailureCategory::OUTPUT_TRUNCATED;
            }
        }

        foreach (self::PROVIDER_REJECTION_MESSAGE_FRAGMENTS as $fragment) {
            if (str_contains($message, $fragment)) {
                return AiFailureCategory::PROVIDER_REJECTION;
            }
        }

        return AiFailureCategory::UNKNOWN;
    }
}
