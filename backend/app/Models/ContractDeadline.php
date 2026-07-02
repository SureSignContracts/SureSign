<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContractDeadline extends Model
{
    use HasFactory, SoftDeletes;

    // Operational lifecycle statuses (do not alter contract wording)
    public const STATUS_PENDING    = 'pending';
    public const STATUS_UPCOMING   = 'upcoming';
    public const STATUS_DUE_TODAY  = 'due_today';
    public const STATUS_COMPLETED  = 'completed';
    public const STATUS_MISSED     = 'missed';
    public const STATUS_WAIVED     = 'waived';
    public const STATUS_CANCELLED  = 'cancelled';

    protected $fillable = [
        'organization_id',
        'project_id',
        'contract_id',
        'contract_ai_analysis_id',
        'name',
        'category',
        'responsible_party',
        'time_period_text',
        'time_period_days',
        'time_direction',
        'trigger_event',
        'recipient',
        'clause_reference',
        'consequence_of_non_compliance',
        'is_statutory',
        'is_recurring',
        'recurrence_description',
        'notes',
        'generates_calendar_event',
        'generates_notification',
        'is_ai_generated',
        'confirmed_at',
    ];

    protected $casts = [
        'is_statutory'             => 'boolean',
        'is_recurring'             => 'boolean',
        'generates_calendar_event' => 'boolean',
        'generates_notification'   => 'boolean',
        'is_ai_generated'          => 'boolean',
        'confirmed_at'             => 'datetime',
        'time_period_days'         => 'integer',
        'resolved_date'            => 'date',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function aiAnalysis(): BelongsTo
    {
        return $this->belongsTo(ContractAiAnalysis::class, 'contract_ai_analysis_id');
    }

    // ── Lifecycle helpers ──────────────────────────────────────────────────────

    public function complete(): void   { $this->update(['status' => self::STATUS_COMPLETED]); }
    public function miss(): void       { $this->update(['status' => self::STATUS_MISSED]); }
    public function waive(): void      { $this->update(['status' => self::STATUS_WAIVED]); }
    public function cancel(): void     { $this->update(['status' => self::STATUS_CANCELLED]); }

    public function isActive(): bool
    {
        return !in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_WAIVED, self::STATUS_CANCELLED, self::STATUS_MISSED]);
    }

    public function isOverdue(): bool
    {
        return $this->resolved_date !== null
            && $this->resolved_date->isPast()
            && !$this->resolved_date->isToday()
            && $this->isActive();
    }

    public function isDueToday(): bool
    {
        return $this->resolved_date?->isToday() && $this->isActive();
    }

    public function daysFromToday(): ?int
    {
        return $this->resolved_date
            ? (int) now()->startOfDay()->diffInDays($this->resolved_date->copy()->startOfDay(), false)
            : null;
    }

    /**
     * Attempt to resolve an absolute date from trigger_event + contract dates.
     * Stores the result in resolved_date if a contract is available.
     */
    public function resolveDate(?Contract $contract = null): ?Carbon
    {
        $contract = $contract ?? $this->contract;
        if (!$contract) return null;

        $trigger   = strtolower($this->trigger_event ?? '');
        $days      = (int) ($this->time_period_days ?? 0);
        $direction = strtolower($this->time_direction ?? 'after');

        $baseDate = $this->resolveBaseDate($trigger, $contract);
        if (!$baseDate) return null;

        if (!$days) {
            return Carbon::parse($baseDate);
        }

        return $direction === 'before'
            ? Carbon::parse($baseDate)->subDays($days)
            : Carbon::parse($baseDate)->addDays($days);
    }

    private function resolveBaseDate(string $trigger, Contract $contract): ?string
    {
        if (str_contains($trigger, 'commencement') || str_contains($trigger, 'start of works')) {
            return $contract->commencement_date?->format('Y-m-d');
        }
        if (str_contains($trigger, 'completion') || str_contains($trigger, 'practical completion')) {
            return $contract->completion_date?->format('Y-m-d');
        }
        if (str_contains($trigger, 'possession')) {
            return ($contract->possession_date ?? $contract->commencement_date)?->format('Y-m-d');
        }
        if (str_contains($trigger, 'base date')) {
            return ($contract->base_date ?? $contract->commencement_date)?->format('Y-m-d');
        }
        if (str_contains($trigger, 'defects') || str_contains($trigger, 'making good')) {
            // Approximate: completion + defects liability period
            if ($contract->completion_date && $contract->defects_liability_period_months) {
                return $contract->completion_date->copy()->addMonths($contract->defects_liability_period_months)->format('Y-m-d');
            }
        }
        return null;
    }
}
