<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EotRequest extends Model
{
    protected $fillable = [
        'project_id', 'organization_id', 'created_by',
        'contract_id', 'trade_package_id', 'delay_event_id',
        'eot_number', 'title', 'notice_date', 'grounds',
        'days_claimed', 'days_granted', 'revised_completion_date', 'status',
        'decided_by', 'decided_at',
    ];

    protected $casts = [
        'notice_date'              => 'date',
        'revised_completion_date'  => 'date',
        'decided_at'               => 'datetime',
    ];

    public function creator()      { return $this->belongsTo(User::class, 'created_by'); }
    public function decisionUser() { return $this->belongsTo(User::class, 'decided_by'); }
    public function project()      { return $this->belongsTo(Project::class); }
    public function contract()     { return $this->belongsTo(Contract::class); }
    public function tradePackage() { return $this->belongsTo(TradePackage::class); }
    public function delayEvent()   { return $this->belongsTo(DelayEvent::class); }
}
