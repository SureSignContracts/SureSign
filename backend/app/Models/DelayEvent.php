<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DelayEvent extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'project_id', 'contract_id', 'trade_package_id',
        'variation_id', 'affected_milestone_id', 'created_by',
        'event_number', 'title', 'description', 'cause_category',
        'date_occurred', 'date_notified', 'notified_by',
        'estimated_delay_days', 'status', 'notes',
    ];

    protected $casts = [
        'date_occurred' => 'date',
        'date_notified' => 'date',
    ];

    public function project()          { return $this->belongsTo(Project::class); }
    public function contract()         { return $this->belongsTo(Contract::class); }
    public function tradePackage()     { return $this->belongsTo(TradePackage::class); }
    public function variation()        { return $this->belongsTo(Variation::class); }
    public function affectedMilestone(){ return $this->belongsTo(ContractProgrammeMilestone::class, 'affected_milestone_id'); }
    public function creator()          { return $this->belongsTo(User::class, 'created_by'); }
}
