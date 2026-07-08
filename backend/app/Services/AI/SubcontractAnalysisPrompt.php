<?php

namespace App\Services\AI;

/**
 * Prompt for Trade Package (subcontract) onboarding analysis.
 *
 * Deliberately narrower than ContractAnalysisPrompt — Sprint 6B Stage 1 only
 * extracts fields that have a real destination today (TradePackage columns +
 * ContractProgrammeMilestone). Risk Register, Insurance, Document Requirements,
 * and Delay & EOT terms are excluded — see Sprint 6B review notes.
 */
class SubcontractAnalysisPrompt
{
    public static function system(): string
    {
        return <<<'PROMPT'
You are assisting with UK construction subcontract administration.

Your task is to extract structured operational information from an executed subcontract document and return it as a single valid JSON object matching the schema exactly.

Core rules:
- Extract only information explicitly stated in the subcontract text. Do not guess, infer, or fabricate.
- If a field is not found, return null. Do not omit fields from the schema.
- For dates, return ISO 8601 (YYYY-MM-DD) if determinable, otherwise return the raw text.
- For monetary amounts, return the numeric value as a string with no currency symbols or commas (e.g. "125000.00").
- For payment offset integers (due_date_offset_days, final_date_offset_days, payment_notice_offset_days, pay_less_notice_offset_days), extract the integer number of days only. Return null if not stated.
- liquidated_damages is usually worded as a rate clause (e.g. "£500 per week or part thereof") — return it as the raw text of the clause, not a parsed number.
- Do not provide legal advice. Describe observations only.
- Your response must be valid JSON only — no markdown fences, no code blocks, no commentary outside the JSON.

Trade identification rules:
- "detected_trade" MUST be exactly one of the known trade package names supplied in the user message, chosen only if the subcontract's scope of works clearly matches it.
- If no known trade clearly matches, set detected_trade to "Other" and describe the actual trade in detected_trade_freeform.
- Never invent a trade name that wasn't supplied. Set detected_trade_confidence to "high", "medium", or "low" based on how explicitly the scope of works states the trade.

Programme milestones:
- Extract every named date-bound milestone (e.g. "Practical Completion of Phase 1", "Handover of Block A") as a separate entry in programme_milestones. Do not include the overall commencement/completion dates here — those go in key_dates.
- Return [] if no distinct named milestones are stated beyond overall commencement/completion.
PROMPT;
    }

    /**
     * @param  string   $subcontractText
     * @param  string[] $catalogueNames  Standard trade package names, e.g. from TradePackageCatalogueService::all()
     */
    public static function user(string $subcontractText, array $catalogueNames): string
    {
        $maxChars  = (int) config('ai.anthropic.max_input_chars', 150000);
        $truncated = mb_substr($subcontractText, 0, $maxChars);
        $catalogueList = implode(', ', array_map(fn ($n) => "\"{$n}\"", $catalogueNames));

        return <<<PROMPT
Extract structured operational information from the following executed subcontract and return a JSON object matching EXACTLY this schema. Use null for fields not found. Return [] for arrays with no items.

Known trade package types to choose "detected_trade" from: {$catalogueList}, or "Other".

{
  "meta": {
    "schema_version": "1.0",
    "confidence": "high",
    "truncated": false,
    "extraction_notes": null
  },
  "general": {
    "subcontract_title": null,
    "subcontract_reference": null,
    "standard_form": null,
    "detected_trade": null,
    "detected_trade_freeform": null,
    "detected_trade_confidence": null
  },
  "contractor": {
    "name": null,
    "contact_name": null,
    "email": null,
    "phone": null,
    "address": null,
    "company_registration_number": null,
    "vat_number": null
  },
  "commercial": {
    "subcontract_sum": null,
    "retention_percentage": null,
    "liquidated_damages": null,
    "payment_terms_days": null,
    "payment_frequency": null,
    "due_date_offset_days": null,
    "final_date_offset_days": null,
    "payment_notice_offset_days": null,
    "pay_less_notice_offset_days": null
  },
  "key_dates": {
    "letter_of_intent_date": null,
    "award_date": null,
    "execution_date": null,
    "commencement_date": null,
    "completion_date": null,
    "defects_liability_end_date": null
  },
  "programme_milestones": [
    { "name": null, "date": null, "notes": null }
  ],
  "missing_information": []
}

SUBCONTRACT TEXT:
{$truncated}
PROMPT;
    }
}
