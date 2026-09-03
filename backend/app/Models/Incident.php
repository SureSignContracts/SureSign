<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Friday Pack Realignment, R1E.2B — Incidents / Accidents / Near Misses.
 * ONE ROW = ONE INDEPENDENTLY RECORDED SAFETY EVENT. Deliberately
 * privacy-minimal — no injured-person identity, worker profile, or
 * medical-record field anywhere in this model, and no attachments in V1
 * (see the R1E.2B checkpoint's own explicit scope limit).
 */
class Incident extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'project_id', 'created_by',
        'occurred_at', 'type', 'title', 'description', 'location',
        'injury_occurred', 'regulatory_reportability', 'status',
    ];

    protected $casts = [
        'occurred_at'      => 'datetime',
        // Nullable boolean — Eloquent's 'boolean' cast preserves null
        // (it only casts non-null values), never silently coerces an
        // unset/null value to false. See this model's own STATES const
        // and the creating migration's docblock for why that distinction
        // is load-bearing here.
        'injury_occurred'  => 'boolean',
    ];

    public const TYPES = ['accident', 'incident', 'near_miss'];
    public const REPORTABILITY_STATES = ['unknown', 'not_reportable', 'reportable'];
    public const STATUSES = ['open', 'closed'];

    public function organization() { return $this->belongsTo(Organization::class); }
    public function project()      { return $this->belongsTo(Project::class); }
    public function creator()      { return $this->belongsTo(User::class, 'created_by'); }

    /** "Accident"/"Incident"/"Near miss" — for activity-log titles, never the raw enum value. */
    public function typeLabel(): string
    {
        return match ($this->type) {
            'accident'  => 'Accident',
            'near_miss' => 'Near miss',
            default     => 'Incident',
        };
    }
}
