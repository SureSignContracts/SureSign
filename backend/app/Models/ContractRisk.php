<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContractRisk extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'project_id',
        'contract_id',
        'trade_package_id',
        'contract_ai_analysis_id',
        'title',
        'description',
        'severity',
        'probability',
        'category',
        'clause_reference',
        'commercial_impact',
        'programme_impact',
        'compliance_impact',
        'urgency',
        'recommended_action',
        'mitigation',
        'risk_owner',
        'is_non_standard_amendment',
        'status',
        'review_date',
        'is_ai_generated',
        'confirmed_at',
    ];

    protected $casts = [
        'is_non_standard_amendment' => 'boolean',
        'is_ai_generated' => 'boolean',
        'confirmed_at' => 'datetime',
        'review_date' => 'date',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function tradePackage(): BelongsTo
    {
        return $this->belongsTo(TradePackage::class);
    }

    public function aiAnalysis(): BelongsTo
    {
        return $this->belongsTo(ContractAiAnalysis::class, 'contract_ai_analysis_id');
    }
}
