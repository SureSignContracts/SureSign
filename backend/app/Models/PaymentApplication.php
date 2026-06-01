<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentApplication extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'project_id', 'contract_id', 'organization_id', 'created_by',
        'application_number', 'reference', 'application_date',
        'due_date', 'gross_valuation', 'less_retention',
        'less_previous_payments', 'amount_due', 'certified_amount',
        'certified_date', 'payment_date', 'paid_amount',
        'status', 'notes', 'breakdown',
    ];

    protected $casts = [
        'application_date'       => 'date',
        'due_date'               => 'date',
        'certified_date'         => 'date',
        'payment_date'           => 'date',
        'gross_valuation'        => 'decimal:2',
        'less_retention'         => 'decimal:2',
        'less_previous_payments' => 'decimal:2',
        'amount_due'             => 'decimal:2',
        'certified_amount'       => 'decimal:2',
        'paid_amount'            => 'decimal:2',
        'breakdown'              => 'array',
    ];

    public function creator()      { return $this->belongsTo(User::class, 'created_by'); }
    public function project()      { return $this->belongsTo(Project::class); }
    public function contract()     { return $this->belongsTo(Contract::class); }
    public function organization() { return $this->belongsTo(Organization::class); }
    public function documents()    { return $this->morphMany(Document::class, 'documentable'); }
}

