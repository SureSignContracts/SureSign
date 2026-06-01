<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Variation extends Model
{
    protected $fillable = [
        'project_id', 'contract_id', 'organization_id', 'created_by',
        'variation_number', 'title', 'type', 'status',
        'quoted_amount', 'agreed_amount', 'description', 'variation_date',
        'programme_impact_days',
    ];

    protected $casts = [
        'variation_date' => 'date',
        'quoted_amount'  => 'decimal:2',
        'agreed_amount'  => 'decimal:2',
    ];

    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function project() { return $this->belongsTo(Project::class); }
    public function contract() { return $this->belongsTo(Contract::class); }
}
