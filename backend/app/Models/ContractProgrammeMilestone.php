<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContractProgrammeMilestone extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'contract_id', 'project_id', 'analysis_id',
        'name', 'milestone_type', 'responsible_party', 'status',
        'planned_date', 'forecast_date', 'actual_date',
        'source_text', 'notes', 'is_ai_generated', 'sort_order',
    ];

    protected $casts = [
        'planned_date'    => 'date',
        'forecast_date'   => 'date',
        'actual_date'     => 'date',
        'is_ai_generated' => 'boolean',
        'agreed_in_writing' => 'boolean',
    ];

    public function contract()  { return $this->belongsTo(Contract::class); }
    public function project()   { return $this->belongsTo(Project::class); }
    public function analysis()  { return $this->belongsTo(ContractAiAnalysis::class, 'analysis_id'); }
}
