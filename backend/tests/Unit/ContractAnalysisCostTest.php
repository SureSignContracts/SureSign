<?php

namespace Tests\Unit;

use App\Services\AI\ContractAnalysisService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Phase G4C.1A.1 — ContractAnalysisService::estimateCost() now resolves
 * effective-dated pricing via AiPricingSchedule, keyed off an explicit
 * provider-call timestamp rather than the current date (see
 * AiPricingScheduleTest for the schedule/boundary logic itself). These
 * tests cover the integration: estimateCost() correctly delegates, rounds,
 * and fails safe (null, never a guessed number) for an unknown model.
 */
class ContractAnalysisCostTest extends TestCase
{
    public function test_cost_uses_the_rate_in_effect_at_the_given_instant(): void
    {
        $service = new ContractAnalysisService();

        $introductory = Carbon::parse('2026-07-26 14:53:53');
        $standard     = Carbon::parse('2026-09-01 00:00:00');

        // 1,000,000 input tokens alone.
        $this->assertSame(2.0, $service->estimateCost(1_000_000, 0, 'claude-sonnet-5', $introductory));
        $this->assertSame(3.0, $service->estimateCost(1_000_000, 0, 'claude-sonnet-5', $standard));

        // 1,000,000 output tokens alone.
        $this->assertSame(10.0, $service->estimateCost(0, 1_000_000, 'claude-sonnet-5', $introductory));
        $this->assertSame(15.0, $service->estimateCost(0, 1_000_000, 'claude-sonnet-5', $standard));
    }

    public function test_cost_matches_the_real_110_page_contract_measurement(): void
    {
        $service = new ContractAnalysisService();

        // Real measured values from the G4C.1B validation run, at the real
        // instant it happened (2026-07-26) — must stay $0.420664 forever,
        // regardless of when this test is run or what the rate becomes later.
        $this->assertEqualsWithDelta(
            0.420664,
            $service->estimateCost(58897, 30287, 'claude-sonnet-5', Carbon::parse('2026-07-26 14:53:53')),
            0.000001
        );
    }

    public function test_cost_rounds_to_six_decimal_places(): void
    {
        $service = new ContractAnalysisService();

        // 1 input token * $2/million = 0.000002.
        $this->assertSame(
            0.000002,
            $service->estimateCost(1, 0, 'claude-sonnet-5', Carbon::parse('2026-07-26'))
        );
    }

    public function test_zero_tokens_is_zero_cost(): void
    {
        $service = new ContractAnalysisService();

        $this->assertSame(
            0.0,
            $service->estimateCost(0, 0, 'claude-sonnet-5', Carbon::parse('2026-07-26'))
        );
    }

    public function test_unknown_model_fails_safe_to_null_rather_than_a_guessed_cost(): void
    {
        $service = new ContractAnalysisService();

        $this->assertNull(
            $service->estimateCost(1000, 500, 'some-future-model-v9', Carbon::parse('2026-07-26'))
        );
    }
}
