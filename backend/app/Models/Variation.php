<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Variation extends Model
{
    // ── Status constants ──────────────────────────────────────────────────────
    // Ordered lifecycle: draft → submitted → instructed → quoted → assessed → approved|rejected
    // 'pending' is kept as a legacy alias for 'draft'.
    // 'on_hold' is a parking state that may be set at any point before approval.
    const STATUS_DRAFT      = 'draft';
    const STATUS_PENDING    = 'pending';      // legacy alias for draft
    const STATUS_SUBMITTED  = 'submitted';
    const STATUS_INSTRUCTED = 'instructed';
    const STATUS_QUOTED     = 'quoted';
    const STATUS_ASSESSED   = 'assessed';
    const STATUS_APPROVED   = 'approved';
    const STATUS_REJECTED   = 'rejected';
    const STATUS_ON_HOLD    = 'on_hold';

    // Statuses that are controlled exclusively by action endpoints
    const WORKFLOW_STATUSES = [
        self::STATUS_SUBMITTED,
        self::STATUS_INSTRUCTED,
        self::STATUS_QUOTED,
        self::STATUS_ASSESSED,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
    ];

    // Statuses that mean the variation is actively progressing (not terminal)
    const IN_PROGRESS_STATUSES = [
        self::STATUS_SUBMITTED,
        self::STATUS_INSTRUCTED,
        self::STATUS_QUOTED,
        self::STATUS_ASSESSED,
    ];

    // ─────────────────────────────────────────────────────────────────────────

    protected $fillable = [
        'project_id', 'contract_id', 'trade_package_id', 'organization_id', 'created_by',
        'variation_number', 'title', 'type', 'status',
        'quoted_amount', 'agreed_amount', 'description', 'variation_date',
        'programme_impact_days',
        'instruction_method', 'written_confirmation_due', 'quotation_due_date',
        'quotation_submitted_at', 'valuation_method', 'agreed_in_writing',
        // Workflow audit fields
        'submitted_at', 'submitted_by',
        'instructed_at', 'instructed_by', 'instruction_notes',
        'quoted_by',
        'assessed_at', 'assessed_by', 'assessment_notes',
        'approved_at', 'approved_by', 'approval_notes',
        'rejected_at', 'rejected_by', 'rejection_reason',
        // Future linkage
        'eot_request_id',
    ];

    protected $casts = [
        'variation_date'           => 'date',
        'written_confirmation_due' => 'date',
        'quotation_due_date'       => 'date',
        'quotation_submitted_at'   => 'date',
        'submitted_at'             => 'datetime',
        'instructed_at'            => 'datetime',
        'assessed_at'              => 'datetime',
        'approved_at'              => 'datetime',
        'rejected_at'              => 'datetime',
        'quoted_amount'            => 'decimal:2',
        'agreed_amount'            => 'decimal:2',
        'agreed_in_writing'        => 'boolean',
        'programme_impact_days'    => 'integer',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function creator()       { return $this->belongsTo(User::class, 'created_by'); }
    public function project()       { return $this->belongsTo(Project::class); }
    public function contract()      { return $this->belongsTo(Contract::class); }
    public function tradePackage()  { return $this->belongsTo(TradePackage::class); }
    public function organization()  { return $this->belongsTo(Organization::class); }

    public function submittedBy()   { return $this->belongsTo(User::class, 'submitted_by'); }
    public function instructedBy()  { return $this->belongsTo(User::class, 'instructed_by'); }
    public function quotedBy()      { return $this->belongsTo(User::class, 'quoted_by'); }
    public function assessedBy()    { return $this->belongsTo(User::class, 'assessed_by'); }
    public function approvedBy()    { return $this->belongsTo(User::class, 'approved_by'); }
    public function rejectedBy()    { return $this->belongsTo(User::class, 'rejected_by'); }

    public function paymentApplicationVariations()
    {
        return $this->hasMany(PaymentApplicationVariation::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /**
     * Whether this variation has been snapshotted into at least one Payment Application.
     * Approved variations that are included must never be silently deleted.
     */
    public function isIncludedInPaymentApplication(): bool
    {
        return $this->paymentApplicationVariations()->exists();
    }

    /**
     * A variation is only deletable when:
     * (a) it is in a non-live status (draft / pending / rejected), AND
     * (b) it has never been included in a Payment Application snapshot.
     */
    public function isDeletable(): bool
    {
        if (!in_array($this->status, [self::STATUS_DRAFT, self::STATUS_PENDING, self::STATUS_REJECTED])) {
            return false;
        }
        return !$this->isIncludedInPaymentApplication();
    }
}
