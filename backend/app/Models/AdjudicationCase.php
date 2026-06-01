<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AdjudicationCase extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'project_id', 'contract_id', 'payment_application_id', 'variation_id',
        'created_by', 'archived_by', 'case_number', 'title', 'dispute_type', 'claimant_name', 'respondent_name',
        'claim_amount', 'currency', 'summary', 'status', 'current_step',
        'notice_of_dispute_date', 'notice_of_adjudication_date', 'referral_due_date',
        'response_due_date', 'decision_due_date', 'decision_received_date', 'enforcement_deadline',
        'metadata', 'archived_at',
    ];

    protected $casts = [
        'claim_amount'                 => 'decimal:2',
        'notice_of_dispute_date'       => 'date',
        'notice_of_adjudication_date'  => 'date',
        'referral_due_date'            => 'date',
        'response_due_date'            => 'date',
        'decision_due_date'            => 'date',
        'decision_received_date'       => 'date',
        'enforcement_deadline'         => 'date',
        'metadata'                     => 'array',
        'archived_at'                  => 'datetime',
    ];

    public const STEPS = [
        'notice_of_dispute'        => 'Notice of Dispute',
        'notice_of_adjudication'   => 'Notice of Adjudication',
        'adjudicator_appointment'  => 'Adjudicator Appointment',
        'referral_submission'      => 'Referral Submission',
        'response_analysis'        => 'Response Analysis',
        'further_submissions'      => 'Further Submissions',
        'decision_analysis'        => 'Decision Analysis',
        'enforcement'              => 'Enforcement',
    ];

    public function organization()        { return $this->belongsTo(Organization::class); }
    public function project()             { return $this->belongsTo(Project::class); }
    public function contract()            { return $this->belongsTo(Contract::class); }
    public function paymentApplication()  { return $this->belongsTo(PaymentApplication::class); }
    public function variation()           { return $this->belongsTo(Variation::class); }
    public function creator()             { return $this->belongsTo(User::class, 'created_by'); }
    public function steps()               { return $this->hasMany(AdjudicationStep::class)->orderBy('sort_order'); }
    public function documents()           { return $this->hasMany(AdjudicationDocument::class); }
    public function deadlines()           { return $this->hasMany(AdjudicationDeadline::class); }
}
