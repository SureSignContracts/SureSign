<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsultationEnquiry extends Model
{
    protected $fillable = [
        'appointment_id', 'consultancy_service_id', 'title', 'description',
        'project_stage', 'contract_form', 'preferred_outcome', 'submitted_by',
        // Consultancy Phase C2, Batch 1 — the engagement-status
        // representation and every Consultancy-owned operational field this
        // phase introduces. See
        // internal-docs/commercial/suresign-consultancy-phase-c2-specification-v1.md §1.
        'engagement_status', 'internal_notes',
        'customer_summary_draft', 'customer_summary_published',
        'customer_summary_published_at', 'customer_summary_published_by',
        'customer_summary_needs_republish',
    ];

    protected $casts = [
        'customer_summary_published_at'    => 'datetime',
        'customer_summary_needs_republish' => 'boolean',
    ];

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function consultancyService(): BelongsTo
    {
        return $this->belongsTo(ConsultancyService::class);
    }

    public function customerSummaryPublishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_summary_published_by');
    }
}
