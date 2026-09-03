<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Toolbox Talks V1A — a short construction-site safety briefing record.
 * Deliberately no SoftDeletes (matches Snag/QaReport, the closest current
 * precedent for a brand-new project module — see the creating migration's
 * own docblock for the full reasoning) and no attendee-identity table
 * (attendee_count only — SureSign has no Worker/Site Operative model).
 */
class ToolboxTalk extends Model
{
    protected $fillable = [
        'organization_id', 'project_id', 'created_by',
        'delivered_by_user_id', 'delivered_by_name',
        'title', 'talk_date', 'started_at', 'location',
        'trade_or_subcontractor', 'summary', 'status', 'attendee_count',
    ];

    protected $casts = [
        'talk_date'      => 'date',
        'attendee_count' => 'integer',
    ];

    public function project()      { return $this->belongsTo(Project::class); }
    public function organization() { return $this->belongsTo(Organization::class); }
    public function creator()      { return $this->belongsTo(User::class, 'created_by'); }
    public function deliveredByUser() { return $this->belongsTo(User::class, 'delivered_by_user_id'); }

    /** Evidence (signed attendance sheets, photos, PDFs) attached specifically to this Toolbox Talk — see FileUpload::attachable(). */
    public function fileUploads() { return $this->morphMany(FileUpload::class, 'attachable'); }
}
