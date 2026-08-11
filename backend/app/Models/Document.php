<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'project_id', 'organization_id', 'created_by', 'template_id',
        'documentable_type', 'documentable_id', 'trade_package_id',
        'title', 'type', 'category', 'reference_number',
        'status', 'file_path', 'preview_pdf_path', 'file_name', 'mime_type', 'file_size',
        'version', 'ai_generated', 'template_data',
        // Mirror tracking
        'mirror_status', 'mirror_path', 'mirrored_at',
    ];

    protected $casts = [
        'ai_generated'  => 'boolean',
        'template_data' => 'array',
        'file_size'     => 'integer',
        'version'       => 'integer',
        'mirrored_at'   => 'datetime',
    ];

    public function project()      { return $this->belongsTo(Project::class); }
    public function organization() { return $this->belongsTo(Organization::class); }
    public function creator()      { return $this->belongsTo(User::class, 'created_by'); }
    public function template()     { return $this->belongsTo(DocumentTemplate::class); }
    public function versions()     { return $this->hasMany(DocumentVersion::class); }
    public function approvals()    { return $this->hasMany(DocumentApproval::class); }
    public function documentable() { return $this->morphTo(); }
    public function tradePackage() { return $this->belongsTo(TradePackage::class); }

    /** The active Drawing registration for this Document, if any — see DrawingController::eligibleDocuments(). */
    public function drawing() { return $this->hasOne(Drawing::class); }
}

