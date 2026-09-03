<?php

namespace App\Models;

use App\Support\FridayPack\FridayPackIntegrityGuard;
use Illuminate\Database\Eloquent\Model;

/**
 * Automated Friday Pack, V1B — the frozen weekly reporting snapshot. See
 * the creating migration for schema/idempotency reasoning and
 * FridayPackIntegrityGuard for the terminal-status immutability rule wired
 * below.
 *
 * CRITICAL: `snapshot_json` is the frozen record. Nothing that reads a
 * FridayPack for display should ever re-derive report content from live
 * project data — see App\Services\FridayPack\FridayPackSnapshotService
 * (the only writer of snapshot_json) and the frontend detail page (the
 * only reader that must render exclusively from it).
 */
class FridayPack extends Model
{
    protected $table = 'friday_packs';

    protected $fillable = [
        'project_id', 'organization_id',
        'week_ending', 'period_start', 'period_end',
        'status',
        'snapshot_json', 'settings_snapshot_json',
        'executive_summary', 'progress_commentary', 'key_concerns', 'next_week_priorities',
        'weekly_summary', 'site_issues_summary', 'look_ahead', 'report_number',
        'generated_at', 'generated_by', 'generation_source',
        'reviewed_at', 'reviewed_by',
        'approved_at', 'approved_by',
        'sent_at', 'sent_by',
        'pdf_document_id',
        'failure_reason',
    ];

    protected $casts = [
        'week_ending'             => 'date',
        'period_start'            => 'date',
        'period_end'              => 'date',
        'snapshot_json'           => 'array',
        'settings_snapshot_json'  => 'array',
        'generated_at'            => 'datetime',
        'reviewed_at'             => 'datetime',
        'approved_at'             => 'datetime',
        'sent_at'                 => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn (self $pack) => FridayPackIntegrityGuard::assertMutable($pack));
    }

    public function project()      { return $this->belongsTo(Project::class); }
    public function organization() { return $this->belongsTo(Organization::class); }
    public function generatedBy()  { return $this->belongsTo(User::class, 'generated_by'); }
    public function reviewedBy()   { return $this->belongsTo(User::class, 'reviewed_by'); }
    public function approvedBy()   { return $this->belongsTo(User::class, 'approved_by'); }
    public function sentBy()       { return $this->belongsTo(User::class, 'sent_by'); }
    public function pdfDocument()  { return $this->belongsTo(Document::class, 'pdf_document_id'); }

    /**
     * V1F — one row per recipient this pack has ever been sent to. Once
     * this relation has ANY row, the pack's PDF is locked (see
     * FridayPackPdfService) and its recipients() are the authoritative
     * delivery record, independent of current FridayPackSettings.recipients.
     */
    public function deliveries()   { return $this->hasMany(FridayPackDelivery::class); }

    /**
     * R1B — the durable Draft photo-curation state, ordered for display.
     * Never mutated except through FridayPackPhotoSelectionService.
     */
    public function photoSelections()
    {
        return $this->hasMany(FridayPackPhotoSelection::class)->orderBy('sort_order');
    }
}
