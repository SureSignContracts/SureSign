<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinalAccount extends Model
{
    use SoftDeletes;

    // ── Status lifecycle ─────────────────────────────────────────────────────
    const STATUS_DRAFT                    = 'draft';
    const STATUS_SUBMITTED                = 'submitted';
    const STATUS_UNDER_REVIEW             = 'under_review';
    const STATUS_AGREED                   = 'agreed';
    const STATUS_SIGNED                   = 'signed';
    const STATUS_FINAL_CERTIFICATE_ISSUED = 'final_certificate_issued';
    const STATUS_COMMERCIALLY_CLOSED      = 'commercially_closed';

    // Statuses after which financial values are locked (no edits allowed)
    const LOCKED_STATUSES = [
        self::STATUS_AGREED,
        self::STATUS_SIGNED,
        self::STATUS_FINAL_CERTIFICATE_ISSUED,
        self::STATUS_COMMERCIALLY_CLOSED,
    ];

    // Statuses that can still return to draft
    const REVERSIBLE_STATUSES = [
        self::STATUS_SUBMITTED,
        self::STATUS_UNDER_REVIEW,
    ];

    // ── Item categories ───────────────────────────────────────────────────────
    const CATEGORY_CONTRACT_SUM       = 'contract_sum';
    const CATEGORY_APPROVED_VARIATION = 'approved_variation';
    const CATEGORY_LOSS_AND_EXPENSE   = 'loss_and_expense';
    const CATEGORY_DAYWORK            = 'daywork';
    const CATEGORY_PROVISIONAL_SUM    = 'provisional_sum';
    const CATEGORY_PRIME_COST_SUM     = 'prime_cost_sum';
    const CATEGORY_CONTRA_CHARGE      = 'contra_charge';
    const CATEGORY_DEDUCTION          = 'deduction';
    const CATEGORY_OTHER              = 'other';

    protected $fillable = [
        'organization_id', 'project_id', 'contract_id', 'trade_package_id',
        'is_trade_package', 'reference', 'status',
        // Snapshot columns — written once at agreement, null before
        'original_contract_sum', 'approved_variations_total',
        'loss_and_expense_total', 'dayworks_total',
        'provisional_sum_adjustment', 'prime_cost_sum_adjustment',
        'contra_charges_total', 'other_adjustments_total',
        'certified_to_date', 'paid_to_date',
        'retention_held', 'retention_released',
        // Lifecycle
        'submitted_at', 'submitted_by',
        'reviewed_at', 'reviewed_by',
        'agreed_at', 'agreed_by',
        'signed_at', 'signed_by',
        'final_certificate_issued_at',
        'dispute_window_expires_at',
        'closed_at', 'closed_by',
        'notes',
    ];

    protected $casts = [
        'is_trade_package'             => 'boolean',
        'original_contract_sum'        => 'decimal:2',
        'approved_variations_total'    => 'decimal:2',
        'loss_and_expense_total'       => 'decimal:2',
        'dayworks_total'               => 'decimal:2',
        'provisional_sum_adjustment'   => 'decimal:2',
        'prime_cost_sum_adjustment'    => 'decimal:2',
        'contra_charges_total'         => 'decimal:2',
        'other_adjustments_total'      => 'decimal:2',
        'certified_to_date'            => 'decimal:2',
        'paid_to_date'                 => 'decimal:2',
        'retention_held'               => 'decimal:2',
        'retention_released'           => 'decimal:2',
        'submitted_at'                 => 'datetime',
        'reviewed_at'                  => 'datetime',
        'agreed_at'                    => 'datetime',
        'signed_at'                    => 'datetime',
        'final_certificate_issued_at'  => 'datetime',
        'dispute_window_expires_at'    => 'date',
        'closed_at'                    => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function organization()   { return $this->belongsTo(Organization::class); }
    public function project()        { return $this->belongsTo(Project::class); }
    public function contract()       { return $this->belongsTo(Contract::class); }
    public function tradePackage()   { return $this->belongsTo(TradePackage::class); }
    public function items()          { return $this->hasMany(FinalAccountItem::class)->orderBy('sort_order')->orderBy('id'); }
    public function documents()      { return $this->morphMany(Document::class, 'documentable'); }

    public function submittedBy()    { return $this->belongsTo(User::class, 'submitted_by'); }
    public function agreedBy()       { return $this->belongsTo(User::class, 'agreed_by'); }
    public function signedBy()       { return $this->belongsTo(User::class, 'signed_by'); }
    public function closedBy()       { return $this->belongsTo(User::class, 'closed_by'); }

    // ── Computed accessors (never stored) ─────────────────────────────────────

    /**
     * Contract sum + all adjustment categories.
     * Before agreement: pass live totals to this method via FinalAccountService.
     * After agreement: use the snapshotted columns.
     */
    public function getAdjustedContractSumAttribute(): ?string
    {
        if (!$this->isSnapshotted()) {
            return null; // Use FinalAccountService::calculateCurrentTotals() before agreement
        }

        $sum = (float) $this->original_contract_sum
            + (float) $this->approved_variations_total
            + (float) $this->loss_and_expense_total
            + (float) $this->dayworks_total
            + (float) $this->provisional_sum_adjustment
            + (float) $this->prime_cost_sum_adjustment
            - (float) $this->contra_charges_total
            + (float) $this->other_adjustments_total;

        return number_format($sum, 2, '.', '');
    }

    public function getRetentionOutstandingAttribute(): ?string
    {
        if (!$this->isSnapshotted()) {
            return null;
        }

        $outstanding = (float) $this->retention_held - (float) $this->retention_released;
        return number_format(max(0, $outstanding), 2, '.', '');
    }

    public function getFinalBalanceDueAttribute(): ?string
    {
        if (!$this->isSnapshotted()) {
            return null;
        }

        $balance = (float) $this->adjusted_contract_sum - (float) $this->certified_to_date;
        return number_format($balance, 2, '.', '');
    }

    // ── Status helpers ────────────────────────────────────────────────────────

    public function isLocked(): bool
    {
        return in_array($this->status, self::LOCKED_STATUSES);
    }

    public function isSnapshotted(): bool
    {
        // Snapshot is written at the agreed transition
        return $this->agreed_at !== null;
    }

    public function isFinalCertificateIssued(): bool
    {
        return in_array($this->status, [
            self::STATUS_FINAL_CERTIFICATE_ISSUED,
            self::STATUS_COMMERCIALLY_CLOSED,
        ]);
    }

    public function canReturnToDraft(): bool
    {
        return in_array($this->status, self::REVERSIBLE_STATUSES);
    }

    /**
     * Days allowed in under_review before the review is flagged overdue.
     * Single source of truth: config/suresign.php ('final_account.review_sla_days').
     * Referenced by this model, OperationalIntelligenceService, and CalendarController.
     */
    public static function reviewSlaDays(): int
    {
        return (int) config('suresign.final_account.review_sla_days', 14);
    }

    /**
     * Days allowed after Final Certificate issuance before close-out is overdue.
     * Single source of truth: config/suresign.php ('final_account.closeout_grace_days').
     */
    public static function closeoutGraceDays(): int
    {
        return (int) config('suresign.final_account.closeout_grace_days', 30);
    }

    /**
     * True when this Final Account has sat in under_review beyond the review SLA.
     */
    public function isReviewOverdue(): bool
    {
        return $this->status === self::STATUS_UNDER_REVIEW
            && $this->reviewed_at !== null
            && $this->reviewed_at->copy()->addDays(self::reviewSlaDays())->isPast();
    }

    /**
     * True when the Final Certificate has been issued but commercial close-out
     * has not happened within the close-out grace period.
     */
    public function isCloseOutOverdue(): bool
    {
        return $this->status === self::STATUS_FINAL_CERTIFICATE_ISSUED
            && $this->final_certificate_issued_at !== null
            && $this->final_certificate_issued_at->copy()->addDays(self::closeoutGraceDays())->isPast();
    }
}
