<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Automated Friday Pack, V1B — one settings row per project. See the
 * creating migration for why this is a dedicated table rather than a JSON
 * column on Project.
 */
class FridayPackSettings extends Model
{
    protected $table = 'friday_pack_settings';

    protected $fillable = [
        'project_id', 'organization_id', 'enabled', 'included_sections',
        // V1E — opt-in scheduled Draft generation; automatic_generation_enabled
        // defaults false at the schema level, never silently turned on.
        'automatic_generation_enabled', 'generation_hour_local',
        // V1F — operational delivery recipients only; never report content,
        // never copied into a FridayPack's own snapshot_json. Snapshotted
        // into durable FridayPackDelivery rows at Send initiation only.
        'recipients',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'enabled'                        => 'boolean',
        'included_sections'              => 'array',
        'automatic_generation_enabled'   => 'boolean',
        'generation_hour_local'          => 'integer',
        'recipients'                     => 'array',
    ];

    /**
     * V1F — recipients as configured right now, normalised to a plain list
     * of ['name' => ?string, 'email' => string]. Never used to resolve an
     * already-initiated delivery — see FridayPackDeliveryService.
     */
    public function recipientsList(): array
    {
        return $this->recipients ?? [];
    }

    public function project()      { return $this->belongsTo(Project::class); }
    public function organization() { return $this->belongsTo(Organization::class); }
    public function creator()      { return $this->belongsTo(User::class, 'created_by'); }
    public function updatedBy()    { return $this->belongsTo(User::class, 'updated_by'); }
}
