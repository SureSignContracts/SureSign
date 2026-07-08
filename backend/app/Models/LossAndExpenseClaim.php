<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LossAndExpenseClaim extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'project_id', 'contract_id', 'trade_package_id',
        'delay_event_id', 'eot_request_id', 'final_account_item_id', 'created_by',
        'claim_number', 'title', 'description',
        'amount_claimed', 'amount_assessed', 'amount_agreed',
        'status', 'notes',
    ];

    protected $casts = [
        'amount_claimed'  => 'decimal:2',
        'amount_assessed' => 'decimal:2',
        'amount_agreed'   => 'decimal:2',
    ];

    public function project()          { return $this->belongsTo(Project::class); }
    public function contract()         { return $this->belongsTo(Contract::class); }
    public function tradePackage()     { return $this->belongsTo(TradePackage::class); }
    public function delayEvent()       { return $this->belongsTo(DelayEvent::class); }
    public function eotRequest()       { return $this->belongsTo(EotRequest::class); }
    public function finalAccountItem() { return $this->belongsTo(FinalAccountItem::class); }
    public function creator()          { return $this->belongsTo(User::class, 'created_by'); }
}
