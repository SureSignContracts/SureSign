<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentNumberSequence extends Model
{
    protected $fillable = ['project_id', 'package_id', 'document_type', 'current_sequence'];

    protected $casts = ['current_sequence' => 'integer'];

    public function project()  { return $this->belongsTo(Project::class); }
    public function package()  { return $this->belongsTo(TradePackage::class, 'package_id'); }
}
