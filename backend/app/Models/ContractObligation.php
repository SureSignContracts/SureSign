<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContractObligation extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'project_id',
        'contract_id',
        'contract_ai_analysis_id',
        'party',
        'title',
        'description',
        'clause_reference',
        'time_period_text',
        'time_period_days',
        'trigger_event',
        'consequence_if_missed',
        'generates_deadline',
        'category',
        'is_ai_generated',
        'confirmed_at',
    ];

    protected $casts = [
        'generates_deadline' => 'boolean',
        'is_ai_generated' => 'boolean',
        'confirmed_at' => 'datetime',
        'time_period_days' => 'integer',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function aiAnalysis(): BelongsTo
    {
        return $this->belongsTo(ContractAiAnalysis::class, 'contract_ai_analysis_id');
    }
}
