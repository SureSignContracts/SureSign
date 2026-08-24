<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentTemplate extends Model
{
    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'category',
        'template_type',
        'type',
        'description',
        'content',
        'variables',
        'file_path',
        'is_global',
        'is_active',
    ];

    protected $casts = [
        'variables'  => 'array',
        'is_global'  => 'boolean',
        'is_active'  => 'boolean',
    ];

    const CATEGORIES = [
        'subcontract'          => 'Subcontract',
        'payment_application'  => 'Payment Application',
        'variation'            => 'Variation',
        'rfi'                  => 'RFI',
        'notice'               => 'Notice',
        'meeting_minutes'      => 'Meeting Minutes',
        'site_report'          => 'Site Report',
        'letter'               => 'Letter',
        'eot'                  => 'EOT',
        'other'                => 'Other',
    ];

    const SUBCONTRACT_TEMPLATE_TYPES = [
        'master_package'           => 'Master Package',
        'procurement_summary'      => 'Procurement Summary',
        'tender_enquiry_letter'    => 'Tender Enquiry Letter',
        'schedule_of_documents'    => 'Schedule of Documents',
        'subcontract_draft'        => 'Subcontract Draft',
        'other'                    => 'Other',
    ];

    const COMMERCIAL_TEMPLATE_TYPES = [
        'payment_application'  => 'Payment Application',
        'payment_certificate'  => 'Payment Certificate',
        'pay_less_notice'      => 'Pay Less Notice',
        'payment_notice'       => 'Payment Notice',
        'variation_schedule'   => 'Variation Schedule',
        'commercial_schedule'  => 'Commercial Schedule',
    ];

    const ALL_TEMPLATE_TYPES = [
        'master_package'           => 'Master Package',
        'procurement_summary'      => 'Procurement Summary',
        'tender_enquiry_letter'    => 'Tender Enquiry Letter',
        'schedule_of_documents'    => 'Schedule of Documents',
        'subcontract_draft'        => 'Subcontract Draft',
        'variation'                => 'Variation',
        'payment_application'      => 'Payment Application',
        'payment_certificate'      => 'Payment Certificate',
        'payment_notice'           => 'Payment Notice',
        'pay_less_notice'          => 'Pay Less Notice',
        'variation_schedule'       => 'Variation Schedule',
        'commercial_schedule'      => 'Commercial Schedule',
        'eot'                      => 'EOT',
        'rfi'                      => 'RFI',
        'meeting_minutes'          => 'Meeting Minutes',
        'site_report'              => 'Site Report',
        'other'                    => 'Other',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public static function generateSlug(string $name): string
    {
        $base = \Illuminate\Support\Str::slug($name);
        $slug = $base;
        $i    = 1;
        while (static::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }

    /**
     * Find the best matching active template for a given category and template_type.
     * Prefers company-specific template over global.
     */
    public static function findForGeneration(string $category, string $templateType, ?int $organizationId = null): ?self
    {
        if ($organizationId) {
            $companySpecific = static::where('category', $category)
                ->where('template_type', $templateType)
                ->where('organization_id', $organizationId)
                ->where('is_active', true)
                ->whereNotNull('file_path')
                ->first();

            if ($companySpecific) {
                return $companySpecific;
            }
        }

        return static::where('category', $category)
            ->where('template_type', $templateType)
            ->where('is_global', true)
            ->where('is_active', true)
            ->whereNotNull('file_path')
            ->first();
    }

    /**
     * P0 Security Remediation (August 24, 2026) — the scoped counterpart to
     * findForGeneration() for a client-supplied explicit template ID (e.g.
     * TradePackagePackageGenerationController's optional `template_id`
     * override). Applies the EXACT same eligibility rules findForGeneration()
     * already enforces for the automatic "best match" lookup — organisation-
     * owned OR explicitly global, active, matching category/template_type,
     * has a file — so an explicit ID can never be more permissive than the
     * automatic path it stands in for. Returns null (never throws) for any
     * ID that doesn't exist, belongs to a different (non-global)
     * organisation, is inactive, or is the wrong category/type — the caller
     * must treat all of these identically (a generic "not available"
     * response) so a foreign template ID cannot be distinguished from a
     * missing/ineligible one.
     */
    public static function findEligibleForGeneration(int $id, string $category, string $templateType, ?int $organizationId = null): ?self
    {
        return static::where('id', $id)
            ->where('category', $category)
            ->where('template_type', $templateType)
            ->where('is_active', true)
            ->whereNotNull('file_path')
            ->where(function ($query) use ($organizationId) {
                $query->where('is_global', true);
                if ($organizationId) {
                    $query->orWhere('organization_id', $organizationId);
                }
            })
            ->first();
    }
}
