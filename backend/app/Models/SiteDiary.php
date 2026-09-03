<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiteDiary extends Model
{
    protected $fillable = [
        'project_id', 'organization_id', 'created_by',
        'diary_date', 'weather', 'temperature', 'workers_on_site',
        'works_carried_out', 'materials_delivered', 'issues', 'visitors', 'status',
    ];

    protected $casts = ['diary_date' => 'date', 'attendees' => 'array'];

    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function project() { return $this->belongsTo(Project::class); }

    /**
     * R1B — Site Report photographic evidence, added as a small extension
     * of the existing generic attachment infrastructure (mirrors
     * ToolboxTalk::fileUploads() exactly; no new attachment mechanism).
     * See App\Services\Documents\RecordAttachmentService and
     * App\Services\FridayPack\FridayPackPhotoDiscoveryService.
     */
    public function fileUploads() { return $this->morphMany(FileUpload::class, 'attachable'); }

    /**
     * R1C — the optional, supplementary trade/role breakdown for this Site
     * Report. `workers_on_site` above remains the authoritative overall
     * headcount; this is never auto-summed into it. See
     * App\Services\FridayPack\FridayPackWorkforceService.
     */
    public function workforceEntries()
    {
        return $this->hasMany(SiteDiaryWorkforceEntry::class)->orderBy('sort_order');
    }
}
