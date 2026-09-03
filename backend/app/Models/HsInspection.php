<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Friday Pack Realignment, R1E.2C — H&S Inspections. ONE ROW = ONE
 * HEALTH & SAFETY INSPECTION/CHECK undertaken on the Project. Deliberately
 * NOT `QaReport` relabelled — this is a genuinely separate domain, reusing
 * only QaReport's engineering pattern (field shape, FileUpload attachment
 * convention), never its table or pass/fail-shaped meaning.
 *
 * `inspected_by` is intentionally free text, not a User FK — a real
 * inspector may be an external H&S consultant, principal contractor
 * personnel, a safety adviser, or another competent person with no
 * SureSign account. This is a deliberate domain decision, not a
 * shortcut; do not add a mandatory User relation here.
 */
class HsInspection extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'project_id', 'created_by',
        'inspection_date', 'inspection_type', 'inspected_by',
        'outcome', 'status', 'findings', 'actions',
    ];

    // Explicit `date:Y-m-d` format — a bare `'date'` cast writes back
    // `Y-m-d H:i:s` on save (Eloquent's default date-cast serialization
    // format), which a real MySQL DATE column silently truncates on
    // INSERT but SQLite's dynamic typing does not. Corrected in R1F.0
    // (Temporal Boundary Integrity Audit) — this column previously still
    // carried the bare-cast defect R1E.2D first found and fixed for
    // `PlantDeployment`; `FridayPackHsInspectionSourceService`'s own
    // `whereBetween()` boundary happened to produce correct results only
    // because it was accidentally paired with this exact defect (the
    // stored `'...  00:00:00'` value string-matched its own
    // `"{$start} 00:00:00"` lower bound) — see that service's own
    // docblock for the full explanation and the corrected query.
    protected $casts = [
        'inspection_date' => 'date:Y-m-d',
    ];

    public const OUTCOMES = ['satisfactory', 'issues_found'];
    public const STATUSES = ['open', 'closed'];

    public function organization() { return $this->belongsTo(Organization::class); }
    public function project()      { return $this->belongsTo(Project::class); }
    public function creator()      { return $this->belongsTo(User::class, 'created_by'); }

    /** Inspection photographs / supporting evidence — see FileUpload::attachable(). */
    public function fileUploads() { return $this->morphMany(FileUpload::class, 'attachable'); }

    /** "Satisfactory"/"Issues Found" — for activity-log titles, never the raw enum value. */
    public function outcomeLabel(): string
    {
        return $this->outcome === 'issues_found' ? 'Issues Found' : 'Satisfactory';
    }
}
