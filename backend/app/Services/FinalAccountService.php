<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\FinalAccount;
use App\Models\FinalAccountItem;
use App\Models\PayLessNotice;
use App\Models\PaymentApplication;
use App\Models\RetentionRelease;
use App\Models\TradePackage;
use App\Models\Variation;
use Illuminate\Support\Facades\DB;

class FinalAccountService
{
    // ── Creation ──────────────────────────────────────────────────────────────

    /**
     * Create a Final Account for a main contract.
     * Generates a reference and seeds initial items from contract data.
     */
    public function createFromContract(Contract $contract, int $userId): FinalAccount
    {
        if ($contract->finalAccount()->exists()) {
            throw new \RuntimeException('A Final Account already exists for this contract.');
        }

        return DB::transaction(function () use ($contract, $userId) {
            $reference = $this->generateReference($contract->organization_id);

            $fa = FinalAccount::create([
                'organization_id'       => $contract->organization_id,
                'project_id'            => $contract->project_id,
                'contract_id'           => $contract->id,
                'trade_package_id'      => null,
                'is_trade_package'      => false,
                'reference'             => $reference,
                'status'                => FinalAccount::STATUS_DRAFT,
                'original_contract_sum' => $contract->contract_sum,
            ]);

            $this->seedItemsFromContract($fa, $contract);

            return $fa;
        });
    }

    /**
     * Create a Final Account for a trade package.
     */
    public function createFromTradePackage(TradePackage $package, int $userId): FinalAccount
    {
        if ($package->finalAccount()->exists()) {
            throw new \RuntimeException('A Final Account already exists for this trade package.');
        }

        return DB::transaction(function () use ($package, $userId) {
            $reference = $this->generateReference($package->organization_id);

            $fa = FinalAccount::create([
                'organization_id'       => $package->organization_id,
                'project_id'            => $package->project_id,
                'contract_id'           => null,
                'trade_package_id'      => $package->id,
                'is_trade_package'      => true,
                'reference'             => $reference,
                'status'                => FinalAccount::STATUS_DRAFT,
                'original_contract_sum' => $package->contract_value ?? 0,
            ]);

            $this->seedItemsFromTradePackage($fa, $package);

            return $fa;
        });
    }

    // ── Seeding ───────────────────────────────────────────────────────────────

    /**
     * Seed initial line items from contract data.
     * This is a one-time operation. Items are then owned by the Final Account.
     */
    public function seedItemsFromContract(FinalAccount $fa, Contract $contract): void
    {
        $sortOrder = 0;

        // 1. Contract sum (locked, not editable)
        FinalAccountItem::create([
            'final_account_id' => $fa->id,
            'category'         => FinalAccount::CATEGORY_CONTRACT_SUM,
            'description'      => 'Original Contract Sum',
            'source_type'      => 'contract',
            'source_id'        => $contract->id,
            'amount'           => $contract->contract_sum ?? 0,
            'is_auto_seeded'   => true,
            'sort_order'       => $sortOrder++,
        ]);

        // 2. Approved variations
        $variations = Variation::where('contract_id', $contract->id)
            ->where('status', Variation::STATUS_APPROVED)
            ->get();

        foreach ($variations as $variation) {
            FinalAccountItem::create([
                'final_account_id' => $fa->id,
                'category'         => FinalAccount::CATEGORY_APPROVED_VARIATION,
                'description'      => $variation->description ?? "Variation {$variation->id}",
                'source_type'      => 'variation',
                'source_id'        => $variation->id,
                'amount'           => $variation->agreed_amount ?? 0,
                'is_auto_seeded'   => true,
                'sort_order'       => $sortOrder++,
            ]);
        }

        // 3. Contra charges from Pay Less Notices (one-time seed, user adjusts)
        $plnDeductions = PayLessNotice::whereHas('paymentApplication', function ($q) use ($contract) {
            $q->where('contract_id', $contract->id);
        })
        ->where('total_deductions', '>', 0)
        ->get();

        foreach ($plnDeductions as $pln) {
            FinalAccountItem::create([
                'final_account_id' => $fa->id,
                'category'         => FinalAccount::CATEGORY_CONTRA_CHARGE,
                'description'      => "Contra charge — Pay Less Notice #{$pln->id}",
                'source_type'      => 'pay_less_notice',
                'source_id'        => $pln->id,
                'amount'           => $pln->total_deductions ?? 0,
                'is_auto_seeded'   => true,
                'sort_order'       => $sortOrder++,
            ]);
        }
    }

    /**
     * Seed initial line items from trade package data.
     */
    public function seedItemsFromTradePackage(FinalAccount $fa, TradePackage $package): void
    {
        $sortOrder = 0;

        FinalAccountItem::create([
            'final_account_id' => $fa->id,
            'category'         => FinalAccount::CATEGORY_CONTRACT_SUM,
            'description'      => 'Original Subcontract Sum',
            'source_type'      => 'trade_package',
            'source_id'        => $package->id,
            'amount'           => $package->contract_value ?? 0,
            'is_auto_seeded'   => true,
            'sort_order'       => $sortOrder++,
        ]);

        $variations = Variation::where('trade_package_id', $package->id)
            ->where('status', Variation::STATUS_APPROVED)
            ->get();

        foreach ($variations as $variation) {
            FinalAccountItem::create([
                'final_account_id' => $fa->id,
                'category'         => FinalAccount::CATEGORY_APPROVED_VARIATION,
                'description'      => $variation->description ?? "Variation {$variation->id}",
                'source_type'      => 'variation',
                'source_id'        => $variation->id,
                'amount'           => $variation->agreed_amount ?? 0,
                'is_auto_seeded'   => true,
                'sort_order'       => $sortOrder++,
            ]);
        }

        $plnDeductions = PayLessNotice::whereHas('paymentApplication', function ($q) use ($package) {
            $q->where('trade_package_id', $package->id);
        })
        ->where('total_deductions', '>', 0)
        ->get();

        foreach ($plnDeductions as $pln) {
            FinalAccountItem::create([
                'final_account_id' => $fa->id,
                'category'         => FinalAccount::CATEGORY_CONTRA_CHARGE,
                'description'      => "Contra charge — Pay Less Notice #{$pln->id}",
                'source_type'      => 'pay_less_notice',
                'source_id'        => $pln->id,
                'amount'           => $pln->total_deductions ?? 0,
                'is_auto_seeded'   => true,
                'sort_order'       => $sortOrder++,
            ]);
        }
    }

    // ── Live calculation (before Agreement) ───────────────────────────────────

    /**
     * Calculate current live totals from source records.
     *
     * Returns an array of financial figures computed directly from the
     * database — not from snapshot columns. Call this whenever you need
     * current values BEFORE the Final Account is agreed.
     *
     * After agreement, read the snapshot columns directly from the model.
     */
    public function calculateCurrentTotals(FinalAccount $fa): array
    {
        $items = $fa->items()->get();

        $approvedVariationsTotal = $items
            ->where('category', FinalAccount::CATEGORY_APPROVED_VARIATION)
            ->sum('amount');

        $lossAndExpenseTotal = $items
            ->where('category', FinalAccount::CATEGORY_LOSS_AND_EXPENSE)
            ->sum('amount');

        $dayworksTotal = $items
            ->where('category', FinalAccount::CATEGORY_DAYWORK)
            ->sum('amount');

        $provisionalSumAdjustment = $items
            ->where('category', FinalAccount::CATEGORY_PROVISIONAL_SUM)
            ->sum('amount');

        $primeCostSumAdjustment = $items
            ->where('category', FinalAccount::CATEGORY_PRIME_COST_SUM)
            ->sum('amount');

        $contraChargesTotal = $items
            ->where('category', FinalAccount::CATEGORY_CONTRA_CHARGE)
            ->sum('amount');

        $otherAdjustmentsTotal = $items
            ->where('category', FinalAccount::CATEGORY_OTHER)
            ->sum('amount')
            + $items->where('category', FinalAccount::CATEGORY_DEDUCTION)->sum('amount');

        $originalContractSum = (float) $fa->original_contract_sum;

        $adjustedContractSum = $originalContractSum
            + (float) $approvedVariationsTotal
            + (float) $lossAndExpenseTotal
            + (float) $dayworksTotal
            + (float) $provisionalSumAdjustment
            + (float) $primeCostSumAdjustment
            - (float) $contraChargesTotal
            + (float) $otherAdjustmentsTotal;

        // Pull certified/paid/retention from payment applications
        [$certifiedToDate, $paidToDate, $retentionHeld] = $this->resolvePaymentTotals($fa);

        // Pull retention released from retention_releases
        $retentionReleased = $this->resolveRetentionReleased($fa);

        $retentionOutstanding = max(0, $retentionHeld - $retentionReleased);
        $finalBalanceDue      = $adjustedContractSum - $certifiedToDate;

        return [
            'original_contract_sum'      => round($originalContractSum, 2),
            'approved_variations_total'  => round((float) $approvedVariationsTotal, 2),
            'loss_and_expense_total'     => round((float) $lossAndExpenseTotal, 2),
            'dayworks_total'             => round((float) $dayworksTotal, 2),
            'provisional_sum_adjustment' => round((float) $provisionalSumAdjustment, 2),
            'prime_cost_sum_adjustment'  => round((float) $primeCostSumAdjustment, 2),
            'contra_charges_total'       => round((float) $contraChargesTotal, 2),
            'other_adjustments_total'    => round((float) $otherAdjustmentsTotal, 2),
            'adjusted_contract_sum'      => round($adjustedContractSum, 2),
            'certified_to_date'          => round($certifiedToDate, 2),
            'paid_to_date'               => round($paidToDate, 2),
            'retention_held'             => round($retentionHeld, 2),
            'retention_released'         => round($retentionReleased, 2),
            'retention_outstanding'      => round($retentionOutstanding, 2),
            'final_balance_due'          => round($finalBalanceDue, 2),
        ];
    }

    // ── Snapshot (Agreement transition) ───────────────────────────────────────

    /**
     * Snapshot all financial values at the moment of Agreement.
     *
     * This is the single most important operation in the Final Account lifecycle.
     * All computed values are frozen atomically inside a transaction.
     * After this, snapshot columns are the source of truth — not live queries.
     *
     * Only valid from under_review status.
     */
    public function snapshotAgreement(FinalAccount $fa, int $userId): FinalAccount
    {
        if ($fa->status !== FinalAccount::STATUS_UNDER_REVIEW) {
            throw new \RuntimeException(
                "Cannot agree a Final Account in status '{$fa->status}'. Must be under_review."
            );
        }

        return DB::transaction(function () use ($fa, $userId) {
            // Lock this row for the duration of the transaction
            $fa = FinalAccount::lockForUpdate()->findOrFail($fa->id);

            $totals = $this->calculateCurrentTotals($fa);

            $fa->update([
                'status'                     => FinalAccount::STATUS_AGREED,
                'original_contract_sum'      => $totals['original_contract_sum'],
                'approved_variations_total'  => $totals['approved_variations_total'],
                'loss_and_expense_total'     => $totals['loss_and_expense_total'],
                'dayworks_total'             => $totals['dayworks_total'],
                'provisional_sum_adjustment' => $totals['provisional_sum_adjustment'],
                'prime_cost_sum_adjustment'  => $totals['prime_cost_sum_adjustment'],
                'contra_charges_total'       => $totals['contra_charges_total'],
                'other_adjustments_total'    => $totals['other_adjustments_total'],
                'certified_to_date'          => $totals['certified_to_date'],
                'paid_to_date'               => $totals['paid_to_date'],
                'retention_held'             => $totals['retention_held'],
                'retention_released'         => $totals['retention_released'],
                'agreed_at'                  => now(),
                'agreed_by'                  => $userId,
            ]);

            return $fa->fresh();
        });
    }

    // ── Lifecycle guards ──────────────────────────────────────────────────────

    /**
     * Determine whether a status transition is permitted.
     *
     * Returns ['allowed' => bool, 'reason' => string|null]
     */
    public function canTransition(FinalAccount $fa, string $toStatus): array
    {
        $from = $fa->status;

        $allowed = match (true) {
            // Forward transitions
            $from === FinalAccount::STATUS_DRAFT && $toStatus === FinalAccount::STATUS_SUBMITTED
                => true,

            $from === FinalAccount::STATUS_SUBMITTED && $toStatus === FinalAccount::STATUS_UNDER_REVIEW
                => true,

            $from === FinalAccount::STATUS_UNDER_REVIEW && $toStatus === FinalAccount::STATUS_AGREED
                => true,

            $from === FinalAccount::STATUS_AGREED && $toStatus === FinalAccount::STATUS_SIGNED
                => true,

            $from === FinalAccount::STATUS_SIGNED && $toStatus === FinalAccount::STATUS_FINAL_CERTIFICATE_ISSUED
                => true,

            $from === FinalAccount::STATUS_FINAL_CERTIFICATE_ISSUED && $toStatus === FinalAccount::STATUS_COMMERCIALLY_CLOSED
                => true,

            // Reversals — only before Agreement
            $from === FinalAccount::STATUS_SUBMITTED && $toStatus === FinalAccount::STATUS_DRAFT
                => true,

            $from === FinalAccount::STATUS_UNDER_REVIEW && $toStatus === FinalAccount::STATUS_DRAFT
                => true,

            $from === FinalAccount::STATUS_UNDER_REVIEW && $toStatus === FinalAccount::STATUS_SUBMITTED
                => true,

            default => false,
        };

        if (!$allowed) {
            return [
                'allowed' => false,
                'reason'  => "Transition from '{$from}' to '{$toStatus}' is not permitted.",
            ];
        }

        // Extra guard: no return to draft after agreement
        if (in_array($from, FinalAccount::LOCKED_STATUSES) && $toStatus === FinalAccount::STATUS_DRAFT) {
            return [
                'allowed' => false,
                'reason'  => 'Final Account cannot return to draft after Agreement. Values are contractually locked.',
            ];
        }

        return ['allowed' => true, 'reason' => null];
    }

    /**
     * Determine whether Half 2 retention can be released for a given
     * contract or trade package.
     *
     * Rules:
     * - If no Final Account exists yet: allow (pre-FA projects should not be blocked).
     * - If a Final Account exists: block unless status is final_certificate_issued
     *   or commercially_closed.
     *
     * @param  int       $contractId      Pass the main contract ID (or null for TP)
     * @param  int|null  $tradePackageId  Pass the trade package ID (or null for main)
     */
    public function canReleaseHalf2Retention(?int $contractId, ?int $tradePackageId): array
    {
        $query = FinalAccount::query();

        if ($contractId) {
            $query->where('contract_id', $contractId);
        } elseif ($tradePackageId) {
            $query->where('trade_package_id', $tradePackageId);
        } else {
            return ['allowed' => true, 'reason' => null];
        }

        $fa = $query->first();

        if (!$fa) {
            // No Final Account started — do not block
            return ['allowed' => true, 'reason' => null];
        }

        if ($fa->isFinalCertificateIssued()) {
            return ['allowed' => true, 'reason' => null];
        }

        return [
            'allowed' => false,
            'reason'  => sprintf(
                'Half 2 retention cannot be released until the Final Certificate has been issued. '
                . 'Current Final Account status: %s.',
                str_replace('_', ' ', $fa->status)
            ),
        ];
    }

    // ── Commercial close-out progress ─────────────────────────────────────────

    /**
     * Informational step-by-step close-out checklist for a Final Account.
     * Each step is marked completed / current (first incomplete step) / remaining.
     */
    public function getCloseOutProgress(FinalAccount $fa): array
    {
        $steps = [
            ['key' => 'created',            'label' => 'Final Account Created',       'completed' => true],
            ['key' => 'agreed',             'label' => 'Agreement',                   'completed' => $fa->agreed_at !== null],
            ['key' => 'signed',             'label' => 'Signed',                      'completed' => $fa->signed_at !== null],
            ['key' => 'certificate_issued', 'label' => 'Final Certificate Issued',    'completed' => $fa->final_certificate_issued_at !== null],
            ['key' => 'half2_released',     'label' => 'Half 2 Retention Released',   'completed' => $this->hasHalf2RetentionReleased($fa)],
            ['key' => 'closed',             'label' => 'Commercially Closed',         'completed' => $fa->status === FinalAccount::STATUS_COMMERCIALLY_CLOSED],
        ];

        $currentAssigned = false;
        foreach ($steps as &$step) {
            if ($step['completed']) {
                $step['state'] = 'completed';
            } elseif (!$currentAssigned) {
                $step['state'] = 'current';
                $currentAssigned = true;
            } else {
                $step['state'] = 'remaining';
            }
        }
        unset($step);

        return $steps;
    }

    /**
     * True if a Half 2 (Making Good Defects) retention release has already
     * been recorded for this Final Account's contract or trade package.
     */
    public function hasHalf2RetentionReleased(FinalAccount $fa): bool
    {
        $query = RetentionRelease::where('moiety', RetentionRelease::MOIETY_HALF_2);

        if ($fa->contract_id) {
            $query->where('contract_id', $fa->contract_id);
        } elseif ($fa->trade_package_id) {
            $query->where('trade_package_id', $fa->trade_package_id);
        } else {
            return false;
        }

        return $query->exists();
    }

    // ── Reference generation ──────────────────────────────────────────────────

    /**
     * Generate the next FA reference for an organisation.
     * Format: FA-0001, FA-0002, etc. — org-scoped, independent of project.
     */
    public function generateReference(int $organizationId): string
    {
        return DB::transaction(function () use ($organizationId) {
            // Count all existing FAs for this org (including soft-deleted) for a monotonic sequence
            $count = FinalAccount::withTrashed()
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->count();

            $next = $count + 1;

            return 'FA-' . str_pad($next, 4, '0', STR_PAD_LEFT);
        });
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Resolve certified/paid/retention totals from payment applications.
     * Only applications in certified or paid status contribute to these totals.
     */
    private function resolvePaymentTotals(FinalAccount $fa): array
    {
        $query = PaymentApplication::whereIn('status', ['certified', 'paid']);

        if ($fa->contract_id) {
            $query->where('contract_id', $fa->contract_id);
        } elseif ($fa->trade_package_id) {
            $query->where('trade_package_id', $fa->trade_package_id);
        } else {
            return [0.0, 0.0, 0.0];
        }

        $apps = $query->get();

        $certifiedToDate = $apps->sum(fn ($a) => (float) $a->certified_amount);
        $paidToDate      = $apps->sum(fn ($a) => (float) $a->paid_amount);
        $retentionHeld   = $apps->sum(fn ($a) => (float) $a->less_retention);

        return [$certifiedToDate, $paidToDate, $retentionHeld];
    }

    /**
     * Sum all retention releases for this contract or trade package.
     */
    private function resolveRetentionReleased(FinalAccount $fa): float
    {
        $query = RetentionRelease::query();

        if ($fa->contract_id) {
            $query->where('contract_id', $fa->contract_id);
        } elseif ($fa->trade_package_id) {
            $query->where('trade_package_id', $fa->trade_package_id);
        } else {
            return 0.0;
        }

        return (float) $query->sum('release_amount');
    }
}
