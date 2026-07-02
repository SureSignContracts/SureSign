<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContractNotice extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'project_id',
        'contract_id',
        'contract_ai_analysis_id',
        'name',
        'notice_type',
        'is_statutory',
        'responsible_party',
        'trigger',
        'time_limit_days',
        'time_direction',
        'time_reference_point',
        'recipient',
        'clause_reference',
        'required_content',
        'consequence_if_missed',
        'can_be_withheld',
        'notes',
        'is_ai_generated',
        'confirmed_at',
    ];

    protected $casts = [
        'is_statutory' => 'boolean',
        'can_be_withheld' => 'boolean',
        'is_ai_generated' => 'boolean',
        'required_content' => 'array',
        'confirmed_at' => 'datetime',
        'time_limit_days' => 'integer',
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
