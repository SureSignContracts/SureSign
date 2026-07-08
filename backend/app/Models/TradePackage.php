<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class TradePackage extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'project_id',
        'name',
        'slug',
        'package_code',
        'package_reference',
        'contractor_name',
        'description',
        'status',
        'created_by',
        'is_custom',
        'created_by_user',
        'original_name',
        'source_type',
        // Commercial terms
        'contract_value',
        'retention_percentage',
        'liquidated_damages',
        'payment_terms_days',
        'payment_frequency',
        // Subcontract dates
        'letter_of_intent_date',
        'award_date',
        'execution_date',
        'commencement_date',
        'completion_date',
        'defects_liability_end_date',
        // Extended contractor details
        'contractor_contact_name',
        'contractor_email',
        'contractor_phone',
        'contractor_address',
        'contractor_company_reg_no',
        'contractor_vat_number',
        // Payment rule offsets (mirror contracts)
        'due_date_offset_days',
        'final_date_offset_days',
        'payment_notice_offset_days',
        'pay_less_notice_offset_days',
    ];

    protected $casts = [
        'contract_value'             => 'decimal:2',
        'retention_percentage'       => 'decimal:2',
        'payment_terms_days'         => 'integer',
        'letter_of_intent_date'      => 'date',
        'award_date'                 => 'date',
        'execution_date'             => 'date',
        'commencement_date'          => 'date',
        'completion_date'            => 'date',
        'defects_liability_end_date' => 'date',
        'due_date_offset_days'        => 'integer',
        'final_date_offset_days'      => 'integer',
        'payment_notice_offset_days'  => 'integer',
        'pay_less_notice_offset_days' => 'integer',
    ];

    /**
     * Valid procurement / subcontract lifecycle statuses.
     * Legacy values (active, inactive, archived) remain valid for backward compatibility.
     */
    public const STATUSES = [
        'tendering',
        'tender_returned',
        'under_review',
        'awarded',
        'documents_issued',
        'executed',
        'active',
        'completed',
        'closed',
        'archived',
        // legacy
        'inactive',
    ];

    // ── Standard folders for every trade package ────────────────────────────

    public const STANDARD_FOLDERS = [
        ['key' => 'tender_enquiry',        'name' => '01 Tender Enquiry',        'sort_order' => 1],
        ['key' => 'schedule_of_documents', 'name' => '02 Schedule of Documents', 'sort_order' => 2],
        ['key' => 'drawings',              'name' => '03 Drawings',              'sort_order' => 3],
        ['key' => 'specifications',        'name' => '04 Specifications',        'sort_order' => 4],
        ['key' => 'pricing_documents',     'name' => '05 Pricing Documents',     'sort_order' => 5],
        ['key' => 'contract_draft',        'name' => '06 Contract Draft',        'sort_order' => 6],
        ['key' => 'correspondence',        'name' => '07 Correspondence',        'sort_order' => 7],
        ['key' => 'returned_tender',       'name' => '08 Returned Tender',       'sort_order' => 8],
        ['key' => 'executed_contract',     'name' => '09 Executed Contract',     'sort_order' => 9],
    ];

    // ── Relationships ───────────────────────────────────────────────────────

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function folders()
    {
        return $this->hasMany(TradePackageFolder::class)->orderBy('sort_order');
    }

    public function fileUploads()
    {
        return $this->hasMany(FileUpload::class, 'trade_package_id');
    }

    public function paymentApplications()
    {
        return $this->hasMany(PaymentApplication::class, 'trade_package_id');
    }

    public function retentionReleases()
    {
        return $this->hasMany(RetentionRelease::class, 'trade_package_id');
    }

    public function variations()
    {
        return $this->hasMany(Variation::class, 'trade_package_id');
    }

    public function finalAccount()
    {
        return $this->hasOne(FinalAccount::class, 'trade_package_id');
    }

    public function aiAnalyses()
    {
        return $this->hasMany(TradePackageAiAnalysis::class);
    }

    /**
     * The authoritative completion date once EOTs are factored in: the most
     * recently granted EOT's revised_completion_date, falling back to the
     * original completion_date if none have been granted. This does not
     * mutate completion_date itself — see EotRequestController::decide().
     */
    public function currentCompletionDate(): ?\Carbon\Carbon
    {
        $latestGranted = EotRequest::where('trade_package_id', $this->id)
            ->where('status', 'granted')
            ->whereNotNull('revised_completion_date')
            ->orderByDesc('decided_at')
            ->first();

        return $latestGranted?->revised_completion_date ?? $this->completion_date;
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Generate a unique slug for a project, appending a counter if needed.
     */
    public static function makeSlug(string $name, int $projectId): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i    = 1;

        while (static::where('project_id', $projectId)->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    /**
     * Create all standard folders for this package.
     */
    public function createStandardFolders(): void
    {
        foreach (self::STANDARD_FOLDERS as $def) {
            $this->folders()->firstOrCreate(
                ['key' => $def['key']],
                ['name' => $def['name'], 'sort_order' => $def['sort_order']]
            );
        }
    }
}
