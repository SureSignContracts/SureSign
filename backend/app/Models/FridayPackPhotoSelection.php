<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Automated Friday Pack, R1B — one row per source photo explicitly
 * selected for a specific Friday Pack. See the creating migration for the
 * full schema-approval reasoning. Caption/location/order live HERE, not
 * on `FileUpload`/`SiteDiary`/`ToolboxTalk` — editing them never mutates
 * the underlying evidence source. Mutations only ever happen through
 * `App\Services\FridayPack\FridayPackPhotoSelectionService` — no
 * controller/other service writes to this table directly.
 */
class FridayPackPhotoSelection extends Model
{
    protected $fillable = [
        'friday_pack_id', 'project_id', 'organization_id',
        'file_upload_id',
        'source_type', 'source_id', 'source_date', 'original_file_name',
        'caption', 'location',
        'sort_order',
        'selected_by',
    ];

    protected $casts = [
        'source_date' => 'date',
        'sort_order'  => 'integer',
    ];

    public function fridayPack()  { return $this->belongsTo(FridayPack::class); }
    public function project()     { return $this->belongsTo(Project::class); }
    public function organization(){ return $this->belongsTo(Organization::class); }
    public function fileUpload()  { return $this->belongsTo(FileUpload::class); }
    public function selectedBy()  { return $this->belongsTo(User::class, 'selected_by'); }
}
