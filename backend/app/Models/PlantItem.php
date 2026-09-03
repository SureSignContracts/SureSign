<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Friday Pack Realignment, R1E.2D — Plant & Equipment. The reusable
 * IDENTITY of a piece/type of plant or equipment on this Project —
 * reusable across reporting weeks/deployment periods, NOT organisation-
 * wide fleet management. `status` is a register/operational state only
 * (`active`/`inactive`) — it never means "on site"/"off site"; physical
 * presence belongs exclusively to `deployments()` (see PlantDeployment's
 * own docblock).
 */
class PlantItem extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'project_id', 'created_by',
        'name', 'type', 'identifier', 'owner_supplier', 'status',
    ];

    public const STATUSES = ['active', 'inactive'];

    public function organization() { return $this->belongsTo(Organization::class); }
    public function project()      { return $this->belongsTo(Project::class); }
    public function creator()      { return $this->belongsTo(User::class, 'created_by'); }

    /** Every recorded site-presence period for this item — see PlantDeployment's own docblock. */
    public function deployments()
    {
        return $this->hasMany(PlantDeployment::class)->orderBy('on_site_from');
    }

    /** Certificates/manuals/photographs — belongs to the reusable item, never to a single deployment period. See FileUpload::attachable(). */
    public function fileUploads() { return $this->morphMany(FileUpload::class, 'attachable'); }

    /** True when this item has at least one currently open (off_site_at IS NULL) deployment. */
    public function hasOpenDeployment(): bool
    {
        return $this->deployments()->whereNull('off_site_at')->exists();
    }
}
