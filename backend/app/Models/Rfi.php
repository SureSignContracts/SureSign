<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Rfi extends Model
{
    protected $fillable = [
        'project_id', 'organization_id', 'created_by',
        'rfi_number', 'subject', 'description', 'priority',
        'status', 'raised_date', 'response_due_date', 'response',
        'assigned_to', 'responded_at',
        'programme_impact', 'programme_impact_days', 'cost_impact_amount',
    ];

    protected $casts = [
        'raised_date'       => 'date',
        'response_due_date' => 'date',
        'responded_at'      => 'date',
        'programme_impact'  => 'boolean',
    ];

    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function project() { return $this->belongsTo(Project::class); }

    /** Evidence/supporting files attached specifically to this RFI — see FileUpload::attachable(). */
    public function fileUploads() { return $this->morphMany(FileUpload::class, 'attachable'); }
}
