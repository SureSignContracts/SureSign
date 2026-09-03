<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Friday Pack Realignment, R1E.2D — Plant & Equipment. ONE ROW = ONE
 * CONTINUOUS PHYSICAL PRESENCE PERIOD for a PlantItem on this Project.
 * `off_site_at = null` means currently/open-ended on site — no separate
 * status column, the date range itself expresses the state.
 *
 * Overlap between two deployments for the SAME `plant_item_id` is never
 * enforced here (a plain Eloquent model has no transaction/locking
 * context) — see App\Services\Plant\PlantDeploymentService, the ONE
 * authoritative place this is checked.
 */
class PlantDeployment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'project_id', 'plant_item_id', 'created_by',
        'on_site_from', 'off_site_at', 'notes',
    ];

    // Explicit `date:Y-m-d` format — a bare `'date'` cast writes back
    // `Y-m-d H:i:s` on save (Eloquent's default date-cast format), which
    // a real MySQL DATE column silently truncates on INSERT but SQLite's
    // dynamic typing does not. Both PlantDeploymentService's overlap
    // check and FridayPackPlantEquipmentSourceService's interval-overlap
    // query compare these columns against plain `Y-m-d` period-boundary
    // strings, so the stored value must always be date-only — verified
    // by a real boundary-equality regression test (a deployment whose
    // `on_site_from` lands exactly on a Friday Pack's own `period_end`).
    protected $casts = [
        'on_site_from' => 'date:Y-m-d',
        'off_site_at'  => 'date:Y-m-d',
    ];

    public function organization() { return $this->belongsTo(Organization::class); }
    public function project()      { return $this->belongsTo(Project::class); }
    public function plantItem()    { return $this->belongsTo(PlantItem::class); }
    public function creator()      { return $this->belongsTo(User::class, 'created_by'); }

    public function isOpen(): bool
    {
        return $this->off_site_at === null;
    }
}
