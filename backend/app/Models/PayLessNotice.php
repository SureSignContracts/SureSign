<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayLessNotice extends Model
{
    protected $fillable = [
        'project_id', 'organization_id', 'created_by',
        'payment_application_id', 'payment_notice_id',
        'notice_date', 'amount', 'notified_sum', 'reason',
        'basis_of_difference', 'reference', 'status',
        'original_amount_due', 'total_deductions', 'revised_amount_payable',
        'deduction_reason', 'detailed_deduction_notes', 'issued_by',
    ];

    protected $casts = [
        'notice_date'            => 'date',
        'amount'                 => 'decimal:2',
        'notified_sum'           => 'decimal:2',
        'original_amount_due'    => 'decimal:2',
        'total_deductions'       => 'decimal:2',
        'revised_amount_payable' => 'decimal:2',
    ];

    public function creator()            { return $this->belongsTo(User::class, 'created_by'); }
    public function project()            { return $this->belongsTo(Project::class); }
    public function paymentApplication() { return $this->belongsTo(PaymentApplication::class); }
    public function paymentNotice()      { return $this->belongsTo(PaymentNotice::class); }
    public function documents()          { return $this->morphMany(\App\Models\Document::class, 'documentable'); }
}
