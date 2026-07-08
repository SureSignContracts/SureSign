<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\FileUpload;
class Contract extends Model {
    use HasFactory, SoftDeletes;
    protected $fillable = [
        'project_id','organization_id','created_by','type','title','reference_number',
        'form_of_contract','standard_form_edition','procurement_route','governing_law',
        'design_responsibility','party_name','employer_name','qs_name',
        'principal_designer','principal_contractor',
        'contract_sum','currency',
        'retention_percentage','retention_cap_percentage',
        'retention_half1_release','retention_half2_release',
        'payment_terms_days','payment_frequency','valuation_method',
        'vat_reverse_charge','performance_bond_required','fluctuations_clause',
        'application_due_day','valuation_period_rule','payment_due_date_rule',
        'final_date_for_payment_rule','pay_less_notice_deadline_rule','payment_notice_deadline_rule',
        'manual_date_override_allowed',
        'execution_date','commencement_date','possession_date','base_date','completion_date',
        'defects_liability_period','defects_liability_period_months',
        'liquidated_damages','notice_requirements','variation_procedure',
        'status','archived_at','notes',
        'key_dates','key_obligations','risks',
        'due_date_offset_days','final_date_offset_days','payment_notice_offset_days','pay_less_notice_offset_days',
    ];

    protected $casts = [
        'contract_sum'                  => 'decimal:2',
        'execution_date'                => 'date',
        'commencement_date'             => 'date',
        'possession_date'               => 'date',
        'base_date'                     => 'date',
        'completion_date'               => 'date',
        'archived_at'                   => 'datetime',
        'key_dates'                     => 'array',
        'key_obligations'               => 'array',
        'risks'                         => 'array',
        'due_date_offset_days'          => 'integer',
        'final_date_offset_days'        => 'integer',
        'payment_notice_offset_days'    => 'integer',
        'pay_less_notice_offset_days'   => 'integer',
        'vat_reverse_charge'            => 'boolean',
        'performance_bond_required'     => 'boolean',
        'defects_liability_period_months' => 'integer',
    ];

    public function project()              { return $this->belongsTo(Project::class); }
    public function organization()         { return $this->belongsTo(Organization::class); }
    public function creator()              { return $this->belongsTo(User::class,'created_by'); }
    public function paymentApplications()  { return $this->hasMany(PaymentApplication::class); }
    public function variations()           { return $this->hasMany(Variation::class); }
    public function eotRequests()          { return $this->hasMany(EotRequest::class); }
    public function fileUploads()          { return $this->morphMany(FileUpload::class, 'attachable'); }
    public function aiAnalyses()           { return $this->hasMany(ContractAiAnalysis::class); }
    public function deadlines()            { return $this->hasMany(ContractDeadline::class); }
    public function notices()              { return $this->hasMany(ContractNotice::class); }
    public function deliverables()         { return $this->hasMany(ContractDeliverable::class); }
    public function contractRisks()        { return $this->hasMany(ContractRisk::class); }
    public function obligations()          { return $this->hasMany(ContractObligation::class); }
    public function finalAccount()         { return $this->hasOne(FinalAccount::class); }

    /**
     * The authoritative completion date once EOTs are factored in: the most
     * recently granted EOT's revised_completion_date, falling back to the
     * original contract completion_date if none have been granted. This does
     * not mutate completion_date itself — see EotRequestController::decide().
     */
    public function currentCompletionDate(): ?\Carbon\Carbon
    {
        $latestGranted = EotRequest::where('contract_id', $this->id)
            ->where('status', 'granted')
            ->whereNotNull('revised_completion_date')
            ->orderByDesc('decided_at')
            ->first();

        return $latestGranted?->revised_completion_date ?? $this->completion_date;
    }

    /**
     * A contract may be hard-deleted only when it is a draft with no linked records.
     * Issued/active contracts and any contract with attached data must be archived instead.
     */
    public function isDeletable(): bool
    {
        if ($this->status !== 'draft') return false;
        if ($this->archived_at) return false;
        if ($this->paymentApplications()->exists()) return false;
        if ($this->variations()->exists()) return false;
        if ($this->eotRequests()->exists()) return false;
        return true;
    }

    public function getDeletableBlockersAttribute(): array
    {
        $blockers = [];
        if ($this->status !== 'draft')
            $blockers[] = 'contract is not a draft (status: ' . $this->status . ')';
        if ($this->archived_at)
            $blockers[] = 'contract is already archived';
        if ($this->paymentApplications()->exists())
            $blockers[] = 'has linked payment applications';
        if ($this->variations()->exists())
            $blockers[] = 'has linked variations';
        if ($this->eotRequests()->exists())
            $blockers[] = 'has linked EOT records';
        return $blockers;
    }
}
