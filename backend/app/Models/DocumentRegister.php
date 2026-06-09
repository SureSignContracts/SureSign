<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentRegister extends Model
{
    protected $table = 'document_register';

    protected $fillable = [
        'document_number', 'title', 'document_type',
        'project_id', 'package_id', 'file_upload_id',
    ];

    public function project()    { return $this->belongsTo(Project::class); }
    public function package()    { return $this->belongsTo(TradePackage::class, 'package_id'); }
    public function fileUpload() { return $this->belongsTo(FileUpload::class); }
}
