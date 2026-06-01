<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FileUpload extends Model
{
    protected $fillable = [
        'project_id', 'organization_id', 'uploaded_by',
        'attachable_type', 'attachable_id',
        'original_name', 'stored_name', 'file_path',
        'mime_type', 'file_size', 'folder_path', 'disk',
        'module_key', 'folder_key',
    ];

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
