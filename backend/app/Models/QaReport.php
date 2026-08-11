<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QaReport extends Model
{
    protected $table = 'qa_reports';

    protected $fillable = [
        'organization_id', 'project_id', 'created_by', 'inspected_by',
        'report_number', 'title', 'inspection_type', 'area',
        'inspection_date', 'status', 'result', 'observations',
        'corrective_action', 'follow_up_required',
    ];

    protected $casts = [
        'inspection_date'   => 'date',
        'follow_up_required' => 'boolean',
    ];

    public function project()    { return $this->belongsTo(Project::class); }
    public function organization(){ return $this->belongsTo(Organization::class); }
    public function creator()    { return $this->belongsTo(User::class, 'created_by'); }
    public function inspector()  { return $this->belongsTo(User::class, 'inspected_by'); }

    /** Inspection photographs and supporting certificates attached specifically to this QA Report — see FileUpload::attachable(). */
    public function fileUploads() { return $this->morphMany(FileUpload::class, 'attachable'); }
}
