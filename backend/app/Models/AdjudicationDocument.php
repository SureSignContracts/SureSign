<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdjudicationDocument extends Model
{
    protected $fillable = [
        'category', 'tags',
        'organization_id', 'project_id', 'adjudication_case_id', 'document_id', 'uploaded_by',
        'title', 'document_type', 'file_path', 'file_name', 'mime_type', 'file_size',
        'version', 'status', 'source_step', 'ai_generated',
        // Mirror tracking
        'mirror_status', 'mirror_path', 'mirrored_at',
    ];

    protected $casts = [
        'ai_generated' => 'boolean',
        'file_size'    => 'integer',
        'tags'         => 'array',
        'mirrored_at'  => 'datetime',
    ];

    public function adjudicationCase() { return $this->belongsTo(AdjudicationCase::class); }
    public function uploadedBy()       { return $this->belongsTo(User::class, 'uploaded_by'); }
    public function document()         { return $this->belongsTo(Document::class); }
}
