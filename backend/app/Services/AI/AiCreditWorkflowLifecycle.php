<?php

namespace App\Services\AI;

use App\Support\AI\AiCreditOperatingMode;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase G4C.3BC — the one integration point both Contract Analysis and
 * Trade Package Analysis call for the reserve → settle/release lifecycle.
 * Designed only from these two workflows' actual shared shape — no hooks
 * for AI Chat/Document Extraction/etc., which don't exist yet.
 *
 * Every method is non-fatal to the caller by contract: a credit-lifecycle
 * failure must never turn a real AI analysis into an error, mirroring
 * AiCreditSimulator's own existing non-fatal contract. Callers should treat
 * these as "best effort accounting," not as something whose exceptions need
 * handling — none escape this class.
 *
 * All three methods below are gated on AiCreditOperatingMode::isDisabled()
 * — when true, nothing in this class touches the ledger at all (no
 * reserve/settle/release row is ever written), matching DISABLED's
 * contract that the accounting lifecycle simply does not run.
 */
class AiCreditWorkflowLifecycle
{
    public function __construct(
        private AiCreditSimulator $simulator,
        private AiCreditBalanceService $balanceService,
        private AiCreditLedgerService $ledgerService,
    ) {
    }

    /**
     * Resolves the shadow amount, checks balance, and reserves if (and only
     * if) an amount resolved. The two returned fields are always persisted
     * onto the caller's analysis row, and their possible values are a
     * deliberate three-way distinction — never conflate them:
     *
     *  - null              — DISABLED mode: the lifecycle was intentionally
     *                         not evaluated at all.
     *  - 'unresolved'       — the lifecycle WAS active (SHADOW/ENFORCED) but
     *                         could not resolve an amount (no shadow policy
     *                         configured, or a caught failure below).
     *  - 'sufficient'/'insufficient' — the lifecycle evaluated successfully.
     *
     * @return array{credit_reservation_amount: ?float, shadow_enforcement_result: ?string}
     */
    public function reserveFor(
        string $workflow,
        string $referenceType,
        int $referenceId,
        int $organizationId,
        int $normalizedInputCharCount
    ): array {
        if (AiCreditOperatingMode::isDisabled()) {
            return ['credit_reservation_amount' => null, 'shadow_enforcement_result' => null];
        }

        try {
            $amount = $this->simulator->resolveShadowAmount($workflow, $normalizedInputCharCount, now());

            if ($amount === null) {
                return ['credit_reservation_amount' => null, 'shadow_enforcement_result' => 'unresolved'];
            }

            $sufficient = $this->balanceService->hasSufficientBalance($organizationId, $amount);

            $this->ledgerService->reserve(
                $organizationId,
                $workflow,
                $referenceType,
                $referenceId,
                $amount,
                $sufficient
                    ? 'AI analysis reservation (shadow mode)'
                    : 'AI analysis reservation (shadow mode — balance would be insufficient; execution not blocked)',
                "{$workflow}:reserve:{$referenceId}"
            );

            return [
                'credit_reservation_amount' => $amount,
                'shadow_enforcement_result' => $sufficient ? 'sufficient' : 'insufficient',
            ];
        } catch (Throwable $e) {
            $this->logFailure('reserve', $workflow, $referenceType, $referenceId, $e);

            return ['credit_reservation_amount' => null, 'shadow_enforcement_result' => 'unresolved'];
        }
    }

    /**
     * Phase G4C.3I — the real enforcement gate. Only ever blocks when the
     * operating mode is ENFORCED AND the reservation's own
     * shadow_enforcement_result is the resolved 'insufficient' — never
     * 'unresolved' (no invented number to enforce against), never null
     * (DISABLED never evaluated anything to enforce), and never in SHADOW
     * mode (today's default, shadow-only behaviour is completely
     * unchanged). Callers should call this immediately after reserveFor()
     * and, if it returns true, throw AiCreditEnforcementException with a
     * customer-safe message rather than calling the provider — see
     * AnalyseContractWithAiJob/AnalyseTradePackageWithAiJob.
     *
     * @param array{credit_reservation_amount: ?float, shadow_enforcement_result: ?string} $reservation
     */
    public function shouldBlock(array $reservation): bool
    {
        return AiCreditOperatingMode::isEnforced()
            && ($reservation['shadow_enforcement_result'] ?? null) === 'insufficient';
    }

    public function settleFor(string $workflow, string $referenceType, int $referenceId): void
    {
        if (AiCreditOperatingMode::isDisabled()) {
            return;
        }

        try {
            $this->ledgerService->settle(
                $referenceType,
                $referenceId,
                'AI analysis completed',
                "{$workflow}:settle:{$referenceId}"
            );
        } catch (Throwable $e) {
            $this->logFailure('settle', $workflow, $referenceType, $referenceId, $e);
        }
    }

    public function releaseFor(string $workflow, string $referenceType, int $referenceId, string $reason): void
    {
        if (AiCreditOperatingMode::isDisabled()) {
            return;
        }

        try {
            $this->ledgerService->release(
                $referenceType,
                $referenceId,
                $reason,
                "{$workflow}:release:{$referenceId}"
            );
        } catch (Throwable $e) {
            $this->logFailure('release', $workflow, $referenceType, $referenceId, $e);
        }
    }

    private function logFailure(string $operation, string $workflow, string $referenceType, int $referenceId, Throwable $e): void
    {
        // Deliberately not fatal — see class docblock. A missing reserve
        // entry when settle()/release() is attempted (AiCreditLedgerStateException)
        // is expected and harmless whenever reserveFor() itself returned
        // 'unresolved' (no reservation was ever opened) — logged at the same
        // level as a genuine ledger error since distinguishing the two here
        // would require re-deriving state this class doesn't need to know.
        Log::error("AiCreditWorkflowLifecycle: {$operation} failed (non-fatal, AI analysis unaffected)", [
            'workflow' => $workflow,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'exception' => $e,
        ]);
    }
}
