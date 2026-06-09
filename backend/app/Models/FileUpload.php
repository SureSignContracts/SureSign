<?php

namespace App\Models;

use App\Models\TradePackage;
use Illuminate\Database\Eloquent\Model;

class FileUpload extends Model
{
    protected $fillable = [
        'project_id', 'organization_id', 'uploaded_by',
        'attachable_type', 'attachable_id',
        'original_name', 'stored_name', 'file_path',
        'mime_type', 'file_size', 'folder_path', 'disk',
        'module_key', 'folder_key',
        'trade_package_id', 'trade_package_folder_key',
        'document_type', 'status',
        'source_type',
        'preview_pdf_path',
        'mirror_status', 'mirror_path', 'mirrored_at',
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

    public function tradePackage()
    {
        return $this->belongsTo(TradePackage::class);
    }
}
