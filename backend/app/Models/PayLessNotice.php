<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayLessNotice extends Model
{
    protected $fillable = [
        'project_id', 'organization_id', 'created_by',
        'notice_date', 'amount', 'reason', 'reference', 'status',
    ];

    protected $casts = ['notice_date' => 'date', 'amount' => 'decimal:2'];

    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function project() { return $this->belongsTo(Project::class); }
}
