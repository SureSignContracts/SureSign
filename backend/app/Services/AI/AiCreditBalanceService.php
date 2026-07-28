<?php

namespace App\Services\AI;

use App\Models\AiCreditLedgerEntry;
use App\Support\AI\AiCreditTransactionType;

/**
 * Phase G4C.3A — derives every balance figure live from
 * ai_credit_ledger_entries on every call. No cached/stored balance column
 * exists anywhere (correctness first — see that migration's own docblock);
 * caching may be added later purely as a performance layer in front of this
 * service, never as a second source of truth.
 *
 * Formulas (see internal-docs/commercial/ai-credit-policy-and-consumption-
 * model-v1.md Part Seven for the worked example this was verified against):
 *
 *   issued    = grant + migration_credit
 *   consumed  = settle
 *   reserved  = reserve - settle - release
 *   available = issued + adjustment_credit - consumed - reserved
 *               - adjustment_debit - expiry - migration_debit
 */
class AiCreditBalanceService
{
    public function balanceFor(int $organizationId): array
    {
        return $this->balanceFromQuery(
            AiCreditLedgerEntry::query()->where('organization_id', $organizationId)
        );
    }

    /**
     * Phase G4C.3D — the exact same formula as balanceFor(), summed across
     * every organisation. Used only by the operations dashboard; never a
     * customer-facing figure. No new accounting logic — same query shape,
     * just without the organization_id filter.
     */
    public function platformBalance(): array
    {
        return $this->balanceFromQuery(AiCreditLedgerEntry::query());
    }

    /**
     * Phase G4C.3D — per-workflow consumption for one organisation, for the
     * Organisation detail page's "AI Workflow Usage" section. Reuses the
     * ledger's own `settle` rows (the same figure balanceFor()'s `consumed`
     * sums, just grouped by workflow instead of collapsed across all of
     * them) — not a new calculation, a narrower slice of the existing one.
     *
     * @return array<string, array{consumed: float, settled_count: int}>
     */
    public function consumedByWorkflow(int $organizationId): array
    {
        return $this->consumedByWorkflowFromQuery(
            AiCreditLedgerEntry::query()->where('organization_id', $organizationId)
        );
    }

    /** Platform-wide equivalent of consumedByWorkflow(), same shape, no organisation filter. */
    public function platformConsumedByWorkflow(): array
    {
        return $this->consumedByWorkflowFromQuery(AiCreditLedgerEntry::query());
    }

    /** @return array<string, array{consumed: float, settled_count: int}> */
    private function consumedByWorkflowFromQuery(\Illuminate\Database\Eloquent\Builder $query): array
    {
        return $query
            ->where('transaction_type', AiCreditTransactionType::SETTLE)
            ->selectRaw('workflow, SUM(amount) as consumed, COUNT(*) as settled_count')
            ->groupBy('workflow')
            ->get()
            ->mapWithKeys(fn ($row) => [
                $row->workflow => ['consumed' => round((float) $row->consumed, 2), 'settled_count' => (int) $row->settled_count],
            ])
            ->all();
    }

    private function balanceFromQuery(\Illuminate\Database\Eloquent\Builder $query): array
    {
        $totals = $query
            ->selectRaw('transaction_type, SUM(amount) as total')
            ->groupBy('transaction_type')
            ->pluck('total', 'transaction_type');

        $sum = fn (string $type): float => (float) ($totals[$type] ?? 0);

        $granted = $sum(AiCreditTransactionType::GRANT);
        $migrationCredit = $sum(AiCreditTransactionType::MIGRATION_CREDIT);
        $migrationDebit = $sum(AiCreditTransactionType::MIGRATION_DEBIT);
        $reserve = $sum(AiCreditTransactionType::RESERVE);
        $settle = $sum(AiCreditTransactionType::SETTLE);
        $release = $sum(AiCreditTransactionType::RELEASE);
        $adjustmentCredit = $sum(AiCreditTransactionType::ADJUSTMENT_CREDIT);
        $adjustmentDebit = $sum(AiCreditTransactionType::ADJUSTMENT_DEBIT);
        $expired = $sum(AiCreditTransactionType::EXPIRY);

        $issued = $granted + $migrationCredit;
        $consumed = $settle;
        $reserved = $reserve - $settle - $release;
        $available = $issued + $adjustmentCredit - $consumed - $reserved - $adjustmentDebit - $expired - $migrationDebit;

        return [
            'issued' => round($issued, 2),
            'consumed' => round($consumed, 2),
            'reserved' => round($reserved, 2),
            'available' => round($available, 2),
            'granted' => round($granted, 2),
            'adjustments_credit' => round($adjustmentCredit, 2),
            'adjustments_debit' => round($adjustmentDebit, 2),
            'expired' => round($expired, 2),
            'migration_credit' => round($migrationCredit, 2),
            'migration_debit' => round($migrationDebit, 2),
        ];
    }

    /**
     * Pure query a future workflow-integration phase can check before
     * deciding whether to call AiCreditLedgerService::reserve() at all —
     * this is the entire "enforcement" surface for now. Nothing in this
     * phase calls it; it exists so enforcement can be wired in later
     * without changing this service's contract.
     */
    public function hasSufficientBalance(int $organizationId, float $amount): bool
    {
        return $this->balanceFor($organizationId)['available'] >= $amount;
    }
}
