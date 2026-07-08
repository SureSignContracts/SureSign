<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContractProgrammeMilestone extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'contract_id', 'trade_package_id', 'project_id', 'analysis_id',
        'name', 'milestone_type', 'responsible_party', 'status',
        'planned_date', 'forecast_date', 'actual_date',
        'planned_start', 'forecast_start', 'actual_start',
        'duration_days', 'progress_pct', 'depends_on', 'group_name',
        'source_text', 'notes', 'is_ai_generated', 'sort_order',
    ];

    protected $casts = [
        'planned_date'    => 'date',
        'forecast_date'   => 'date',
        'actual_date'     => 'date',
        'planned_start'   => 'date',
        'forecast_start'  => 'date',
        'actual_start'    => 'date',
        'depends_on'      => 'array',
        'is_ai_generated' => 'boolean',
        'agreed_in_writing' => 'boolean',
    ];

    // planned_finish / forecast_finish / actual_finish are not stored columns —
    // for a plain milestone (no duration) the "finish" date IS the milestone
    // date, so these accessors fall back to the existing *_date columns. Once
    // an activity has its own distinct finish, store it there instead and
    // these accessors just pass it through.
    protected $appends = ['planned_finish', 'forecast_finish', 'actual_finish'];

    public function getPlannedFinishAttribute()  { return $this->planned_date; }
    public function getForecastFinishAttribute() { return $this->forecast_date; }
    public function getActualFinishAttribute()   { return $this->actual_date; }

    public function contract()     { return $this->belongsTo(Contract::class); }
    public function tradePackage() { return $this->belongsTo(TradePackage::class); }
    public function project()      { return $this->belongsTo(Project::class); }
    public function analysis()     { return $this->belongsTo(ContractAiAnalysis::class, 'analysis_id'); }
}
