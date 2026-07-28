<?php

namespace Tests\Unit;

use App\Services\AI\ContractAnalysisPrompt;
use PHPUnit\Framework\TestCase;

/**
 * Covers the G4C.1A fix: ContractAiAnalysis::summary was silently left null
 * for every schema v2.0 analysis because AnalyseContractWithAiJob/
 * AiController::reparseAnalysis() read a flat 'contract_summary' key that
 * schema v2.0 never produces — the real value lives at
 * executive_summary.commercial_summary. ContractAnalysisPrompt::extractSummary()
 * is now the single place this is resolved, with a v1 fallback preserved for
 * already-completed legacy analyses.
 */
class ContractAnalysisPromptSummaryTest extends TestCase
{
    public function test_extracts_the_v2_schema_summary(): void
    {
        $data = ['executive_summary' => ['commercial_summary' => 'A v2 summary.']];

        $this->assertSame('A v2 summary.', ContractAnalysisPrompt::extractSummary($data));
    }

    public function test_falls_back_to_the_legacy_v1_flat_key(): void
    {
        $data = ['contract_summary' => 'A v1 summary.'];

        $this->assertSame('A v1 summary.', ContractAnalysisPrompt::extractSummary($data));
    }

    public function test_prefers_v2_when_both_keys_are_present(): void
    {
        $data = [
            'executive_summary' => ['commercial_summary' => 'v2 wins.'],
            'contract_summary'  => 'v1 loses.',
        ];

        $this->assertSame('v2 wins.', ContractAnalysisPrompt::extractSummary($data));
    }

    public function test_returns_null_when_neither_key_is_present(): void
    {
        $this->assertNull(ContractAnalysisPrompt::extractSummary(['some_other_field' => 'x']));
    }

    public function test_returns_null_when_executive_summary_has_no_commercial_summary(): void
    {
        $data = ['executive_summary' => ['overall_risk_rating' => 'medium']];

        $this->assertNull(ContractAnalysisPrompt::extractSummary($data));
    }

    public function test_truncates_long_values(): void
    {
        $long = str_repeat('a', 1500);

        $result = ContractAnalysisPrompt::extractSummary(['contract_summary' => $long]);

        // Str::limit(..., 1000) truncates to 1000 chars then appends '...'.
        $this->assertSame(1003, mb_strlen($result));
        $this->assertStringEndsWith('...', $result);
    }
}
