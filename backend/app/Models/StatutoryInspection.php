<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Friday Pack Realignment, R1E.2E — Statutory Inspections. ONE ROW = ONE
 * INSPECTION/CHECK EVENT — deliberately NOT a DeliveryDocument (compliance
 * document metadata), NOT an HsInspection (general H&S inspection
 * activity), and NOT a PlantDeployment (physical site presence). All four
 * remain fully independent tables/models.
 *
 * Supports BOTH plant/equipment-linked inspections (e.g. a Tower Crane's
 * lifting equipment inspection) AND non-plant inspection subjects (e.g. a
 * scaffold or temporary-works inspection) — `plant_item_id` is nullable.
 * `subject_description` is always required and always an explicit,
 * independently-recorded description of what was inspected, even when
 * `plant_item_id` is also supplied — see `plantItem()`'s own docblock for
 * why this record must never rely exclusively on a live PlantItem lookup
 * for its own historical meaning.
 *
 * `outcome` (what the inspection found) and `status` (lifecycle) are
 * deliberately independent — never derive one from the other.
 * `next_due_date` is an explicitly recorded date only; this model/its
 * Friday Pack collector never calculates statutory inspection frequency
 * or an "overdue" state from it.
 */
class StatutoryInspection extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'project_id', 'plant_item_id', 'created_by',
        'inspection_date', 'inspection_type', 'subject_description', 'reference',
        'outcome', 'status', 'notes', 'next_due_date',
    ];

    // Explicit `date:Y-m-d` format on both local-date fields — a bare
    // `'date'` cast writes back `Y-m-d H:i:s` on save (Eloquent's default
    // date-cast serialization format), which a real MySQL DATE column
    // silently truncates on INSERT but SQLite's dynamic typing does not.
    // R1E.2D's `PlantDeployment` found this exact defect via a Friday
    // Pack boundary-equality test (a record landing exactly on a
    // reporting period's own edge date was silently excluded under
    // SQLite) — see that model's own docblock. `date:Y-m-d` is now this
    // initiative's established safe convention for every local date field
    // a Friday Pack source query compares against a plain `Y-m-d`
    // period-boundary string.
    protected $casts = [
        'inspection_date' => 'date:Y-m-d',
        'next_due_date'   => 'date:Y-m-d',
    ];

    public const OUTCOMES = ['satisfactory', 'issues_found'];
    public const STATUSES = ['open', 'closed'];

    public function organization() { return $this->belongsTo(Organization::class); }
    public function project()      { return $this->belongsTo(Project::class); }
    public function creator()      { return $this->belongsTo(User::class, 'created_by'); }

    /**
     * Deliberately resolves a soft-deleted linked PlantItem too
     * (`withTrashed()`) — a StatutoryInspection must remain historically
     * meaningful even after its linked PlantItem is later soft-deleted
     * (e.g. the item was decommissioned/removed from the fleet). This is
     * a HISTORICAL relationship, never a claim the linked item is still
     * active/current — callers displaying this relation must present it
     * as historical detail, never as live plant status.
     */
    public function plantItem()
    {
        return $this->belongsTo(PlantItem::class)->withTrashed();
    }

    /** Inspection certificates / photographs / check sheets — see FileUpload::attachable(). */
    public function fileUploads() { return $this->morphMany(FileUpload::class, 'attachable'); }

    /** "Satisfactory"/"Issues Found" — for activity-log titles, never the raw enum value. */
    public function outcomeLabel(): string
    {
        return $this->outcome === 'issues_found' ? 'Issues Found' : 'Satisfactory';
    }
}
