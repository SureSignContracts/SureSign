<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Snag extends Model
{
    protected $fillable = [
        'organization_id', 'project_id', 'created_by', 'assigned_to',
        'snag_number', 'title', 'description', 'location', 'category',
        'priority', 'status', 'due_date', 'closed_at', 'notes',
    ];

    protected $casts = [
        'due_date'   => 'date',
        'closed_at'  => 'datetime',
    ];

    public function project()      { return $this->belongsTo(Project::class); }
    public function organization() { return $this->belongsTo(Organization::class); }
    public function creator()      { return $this->belongsTo(User::class, 'created_by'); }
    public function assignee()     { return $this->belongsTo(User::class, 'assigned_to'); }

    /** Evidence/defect photos and supporting files attached specifically to this Snag — see FileUpload::attachable(). */
    public function fileUploads() { return $this->morphMany(FileUpload::class, 'attachable'); }
}
