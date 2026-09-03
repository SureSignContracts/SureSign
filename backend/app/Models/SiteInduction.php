<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Friday Pack Realignment, R1E.2A — Site Inductions. ONE ROW = ONE
 * INDUCTION SESSION — never one individual worker, never a
 * meaning-shifting daily aggregate. No individual worker identity
 * anywhere in this model (aggregate `inductee_count` only, mirroring
 * ToolboxTalk::$attendee_count's own precedent — SureSign has no Worker/
 * Site Operative model).
 */
class SiteInduction extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'project_id', 'created_by',
        'induction_date', 'session_title', 'company_or_trade',
        'inductee_count', 'notes',
    ];

    protected $casts = [
        'induction_date'  => 'date',
        'inductee_count'  => 'integer',
    ];

    public function project()      { return $this->belongsTo(Project::class); }
    public function organization() { return $this->belongsTo(Organization::class); }
    public function creator()      { return $this->belongsTo(User::class, 'created_by'); }

    /** Optional session evidence (sign-in sheet, induction sheet, site photo) — see FileUpload::attachable(). */
    public function fileUploads() { return $this->morphMany(FileUpload::class, 'attachable'); }
}
