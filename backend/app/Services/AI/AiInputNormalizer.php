<?php

namespace App\Services\AI;

/**
 * Phase G4C.2C-2 — the deterministic, provider-independent input
 * normalisation contract for AI Credit simulation. Operates only on
 * already-extracted document text (the same text
 * ContractAnalysisService::extractText()/TradePackageAnalysisService
 * already produce before ever calling the provider) — never on provider
 * tokens, provider responses, prompts, or schemas.
 *
 * The same source text always produces the same normalized measurement,
 * regardless of provider, model, or tokenizer — that's the entire point:
 * a customer-facing credit charge derived from this must never move
 * because SureSign changed AI vendor (see the AI Credit policy doc,
 * Part Two §33).
 *
 * Deliberately does NOT redefine `document_char_count` (G4C.1's existing
 * raw telemetry field, `mb_strlen()` of unmodified extracted text) —
 * `normalizedCharCount()` is an additive, separate concept.
 */
class AiInputNormalizer
{
    /**
     * Bump this whenever the normalization rules below change. Every
     * simulation result records the version that produced it, so a rule
     * change never silently reinterprets an already-calculated result —
     * it invalidates it for recalculation instead (see
     * AiCreditSimulator's own docblock).
     */
    public const VERSION = 'v1';

    /**
     * Extraction-only structural separators that are artefacts of how a
     * document was extracted, not real content — e.g.
     * ContractAnalysisService::extractTextFromElement() inserts a literal
     * " | " between DOCX table cells (found during the G4C.2C-1 audit).
     * Stripped before measuring so a table-heavy DOCX doesn't measure
     * artificially larger than an equivalent PDF/TXT with the same real
     * content.
     */
    private const EXTRACTION_ARTEFACTS = [' | '];

    public static function normalize(string $rawText): string
    {
        // 1. UTF-8 safety first — mirrors ContractAnalysisService::parseJsonResponse()'s
        //    existing convention (DOCX extraction may produce non-UTF-8 bytes).
        $text = mb_convert_encoding($rawText, 'UTF-8', 'UTF-8');

        // 2. Line-ending normalization — \r\n and lone \r both become \n,
        //    so the same content extracted on different platforms measures
        //    identically.
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // 3. Strip known extraction-only structural separators.
        $text = str_replace(self::EXTRACTION_ARTEFACTS, ' ', $text);

        // 4. Collapse all runs of whitespace (including newlines) to a
        //    single space — a loosely-formatted document must not measure
        //    larger than an equivalent tightly-formatted one with the same
        //    real content.
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        // 5. Trim leading/trailing whitespace.
        return trim($text);
    }

    /**
     * The normalized character count — the provider-independent measurement
     * AiCreditSimulator resolves bands from. Returns 0 for empty/whitespace-
     * only input (callers should treat that as "unavailable", not a real
     * measurement — a genuinely empty extraction already fails validation
     * upstream in ContractAnalysisService::analyse() before this is ever
     * reached in the prospective path).
     */
    public static function normalizedCharCount(string $rawText): int
    {
        return mb_strlen(self::normalize($rawText));
    }
}
