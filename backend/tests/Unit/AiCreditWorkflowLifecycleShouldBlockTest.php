<?php

namespace Tests\Unit;

use App\Models\SuresignSetting;
use App\Services\AI\AiCreditBalanceService;
use App\Services\AI\AiCreditLedgerService;
use App\Services\AI\AiCreditSimulator;
use App\Services\AI\AiCreditWorkflowLifecycle;
use App\Support\AI\AiCreditOperatingMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase G4C.3I (superseded to a three-state operating mode) — pure
 * decision-table coverage for the real enforcement gate. Full end-to-end
 * blocking behaviour (provider never called, ledger released,
 * failure_category recorded) is covered by AiCreditWorkflowIntegrationTest;
 * this file isolates just the gate's own boolean logic against the Super
 * Admin ai_credit_operating_mode setting (suresign_settings).
 */
class AiCreditWorkflowLifecycleShouldBlockTest extends TestCase
{
    use RefreshDatabase;

    private function lifecycle(): AiCreditWorkflowLifecycle
    {
        return new AiCreditWorkflowLifecycle(
            app(AiCreditSimulator::class),
            app(AiCreditBalanceService::class),
            app(AiCreditLedgerService::class),
        );
    }

    private function setMode(string $mode): void
    {
        SuresignSetting::instance()->update(['ai_credit_operating_mode' => $mode]);
    }

    public function test_blocks_only_when_mode_is_enforced_and_result_is_insufficient(): void
    {
        $this->setMode(AiCreditOperatingMode::ENFORCED);
        $this->assertTrue($this->lifecycle()->shouldBlock(['credit_reservation_amount' => 10.0, 'shadow_enforcement_result' => 'insufficient']));
    }

    public function test_never_blocks_in_shadow_mode(): void
    {
        $this->setMode(AiCreditOperatingMode::SHADOW);
        $this->assertFalse($this->lifecycle()->shouldBlock(['credit_reservation_amount' => 10.0, 'shadow_enforcement_result' => 'insufficient']));
    }

    public function test_never_blocks_in_disabled_mode(): void
    {
        $this->setMode(AiCreditOperatingMode::DISABLED);
        $this->assertFalse($this->lifecycle()->shouldBlock(['credit_reservation_amount' => null, 'shadow_enforcement_result' => null]));
    }

    public function test_never_blocks_a_sufficient_result_even_when_enforced(): void
    {
        $this->setMode(AiCreditOperatingMode::ENFORCED);
        $this->assertFalse($this->lifecycle()->shouldBlock(['credit_reservation_amount' => 10.0, 'shadow_enforcement_result' => 'sufficient']));
    }

    public function test_never_blocks_an_unresolved_result_even_when_enforced(): void
    {
        $this->setMode(AiCreditOperatingMode::ENFORCED);
        $this->assertFalse($this->lifecycle()->shouldBlock(['credit_reservation_amount' => null, 'shadow_enforcement_result' => 'unresolved']));
    }

    public function test_never_blocks_a_null_result_even_when_enforced(): void
    {
        // A null shadow_enforcement_result should never occur alongside
        // ENFORCED in practice (reserveFor() only returns null while
        // DISABLED), but the gate itself must still fail safe if it did.
        $this->setMode(AiCreditOperatingMode::ENFORCED);
        $this->assertFalse($this->lifecycle()->shouldBlock(['credit_reservation_amount' => null, 'shadow_enforcement_result' => null]));
    }

    public function test_defaults_to_shadow_and_does_not_block(): void
    {
        $this->assertSame(AiCreditOperatingMode::SHADOW, SuresignSetting::instance()->ai_credit_operating_mode);
        $this->assertFalse($this->lifecycle()->shouldBlock(['credit_reservation_amount' => 10.0, 'shadow_enforcement_result' => 'insufficient']));
    }

    public function test_unrecognised_stored_value_fails_safe_to_not_blocking(): void
    {
        SuresignSetting::instance()->update(['ai_credit_operating_mode' => 'garbage']);
        $this->assertSame(AiCreditOperatingMode::SHADOW, AiCreditOperatingMode::current());
        $this->assertFalse($this->lifecycle()->shouldBlock(['credit_reservation_amount' => 10.0, 'shadow_enforcement_result' => 'insufficient']));
    }
}
