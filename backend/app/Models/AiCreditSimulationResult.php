<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Phase G4C.2C-2 — a single candidate policy's non-enforcing, informational
 * hypothetical credit result for one already-completed AI execution.
 *
 * NOT a ledger entry, account balance, financial transaction, reservation,
 * settlement, or entitlement usage event. Deleting every row in this table
 * has zero accounting consequence — see the owning migration's docblock.
 * Written and recalculated exclusively by App\Services\AI\AiCreditSimulator;
 * never read by FeatureGate, any customer-facing controller/presenter, or
 * any billing service.
 */
class AiCreditSimulationResult extends Model
{
    protected $fillable = [
        'analysable_type',
        'analysable_id',
        'workflow',
        'organization_id',
        'candidate_policy_key',
        'candidate_policy_version',
        'charging_strategy',
        'normalization_version',
        'normalized_input_char_count',
        'hypothetical_band',
        'hypothetical_credits',
        'simulation_status',
        'resolution_reason',
        'source',
        'calculated_at',
    ];

    protected $casts = [
        'candidate_policy_version' => 'integer',
        'normalized_input_char_count' => 'integer',
        'hypothetical_credits' => 'decimal:2',
        'calculated_at' => 'datetime',
    ];

    public function analysable(): MorphTo
    {
        return $this->morphTo();
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
