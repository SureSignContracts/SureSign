<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentApplication extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'project_id', 'contract_id', 'trade_package_id', 'organization_id', 'created_by',
        'application_number', 'reference', 'application_date',
        'valuation_period_start', 'valuation_period_end',
        'due_date', 'final_date_for_payment', 'payment_notice_deadline', 'pay_less_notice_deadline',
        'gross_valuation', 'less_retention', 'less_previous_payments', 'amount_due',
        'previous_certified_value', 'previous_paid_value', 'previous_retention_held',
        'previous_gross_valuation', 'previous_applications_count',
        'certified_amount', 'certified_date', 'payment_date', 'paid_amount',
        'status', 'notes', 'breakdown',
        'use_breakdown', 'vat_rate', 'vat_amount', 'total_due_including_vat',
        'measured_works_total', 'variations_total', 'materials_on_site_total', 'linked_variations_total',
        'submitted_at', 'submitted_by',
        'withdrawal_count', 'withdrawn_at', 'withdrawn_by', 'withdrawal_reason',
        'certified_at', 'certified_by', 'certificate_reference', 'certificate_notes',
        'paid_at', 'paid_by', 'payment_reference',
        'cancelled_at',
    ];

    protected $casts = [
        'application_date'          => 'date',
        'valuation_period_start'    => 'date',
        'valuation_period_end'      => 'date',
        'due_date'                  => 'date',
        'final_date_for_payment'    => 'date',
        'payment_notice_deadline'   => 'date',
        'pay_less_notice_deadline'  => 'date',
        'certified_date'            => 'date',
        'payment_date'              => 'date',
        'submitted_at'              => 'datetime',
        'withdrawn_at'              => 'datetime',
        'withdrawal_count'          => 'integer',
        'certified_at'              => 'datetime',
        'paid_at'                   => 'datetime',
        'cancelled_at'              => 'datetime',
        'gross_valuation'           => 'decimal:2',
        'less_retention'            => 'decimal:2',
        'less_previous_payments'    => 'decimal:2',
        'amount_due'                => 'decimal:2',
        'previous_certified_value'  => 'decimal:2',
        'previous_paid_value'       => 'decimal:2',
        'previous_retention_held'   => 'decimal:2',
        'previous_gross_valuation'  => 'decimal:2',
        'certified_amount'          => 'decimal:2',
        'paid_amount'               => 'decimal:2',
        'vat_rate'                  => 'decimal:2',
        'vat_amount'                => 'decimal:2',
        'total_due_including_vat'   => 'decimal:2',
        'measured_works_total'      => 'decimal:2',
        'variations_total'          => 'decimal:2',
        'materials_on_site_total'   => 'decimal:2',
        'linked_variations_total'   => 'decimal:2',
        'use_breakdown'             => 'boolean',
        'breakdown'                 => 'array',
    ];

    public function creator()          { return $this->belongsTo(User::class, 'created_by'); }
    public function project()          { return $this->belongsTo(Project::class); }
    public function contract()         { return $this->belongsTo(Contract::class); }
    public function tradePackage()     { return $this->belongsTo(TradePackage::class); }
    public function organization()     { return $this->belongsTo(Organization::class); }
    public function documents()        { return $this->morphMany(Document::class, 'documentable'); }
    public function paymentNotices()   { return $this->hasMany(PaymentNotice::class); }
    public function payLessNotices()   { return $this->hasMany(PayLessNotice::class); }
    public function linkedVariations() { return $this->hasMany(PaymentApplicationVariation::class); }
}
