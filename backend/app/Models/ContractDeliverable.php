<?php

namespace App\Models;

use App\Services\TimezoneResolver;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContractDeliverable extends Model
{
    use HasFactory, SoftDeletes;

    // Operational lifecycle statuses (do not alter contract data)
    public const STATUS_PENDING     = 'pending';
    public const STATUS_REQUIRED    = 'required';
    public const STATUS_SUBMITTED   = 'submitted';
    public const STATUS_ACCEPTED    = 'accepted';
    public const STATUS_REJECTED    = 'rejected';
    public const STATUS_OUTSTANDING = 'outstanding';
    public const STATUS_OVERDUE     = 'overdue';
    public const STATUS_CANCELLED   = 'cancelled';

    protected $fillable = [
        'organization_id',
        'project_id',
        'contract_id',
        'contract_ai_analysis_id',
        'name',
        'category',
        'required',
        'responsible_party',
        'due_event',
        'due_days_before_after_event',
        'format',
        'copies_required',
        'clause_reference',
        'recipient',
        'consequence_if_late',
        'notes',
        'status',
        'is_ai_generated',
        'confirmed_at',
    ];

    protected $casts = [
        'required'                   => 'boolean',
        'is_ai_generated'            => 'boolean',
        'confirmed_at'               => 'datetime',
        'due_days_before_after_event' => 'integer',
        'resolved_date'              => 'date',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function aiAnalysis(): BelongsTo
    {
        return $this->belongsTo(ContractAiAnalysis::class, 'contract_ai_analysis_id');
    }

    // ── Lifecycle helpers ──────────────────────────────────────────────────────

    public function submit(): void   { $this->update(['status' => self::STATUS_SUBMITTED]); }
    public function accept(): void   { $this->update(['status' => self::STATUS_ACCEPTED]); }
    public function reject(): void   { $this->update(['status' => self::STATUS_REJECTED]); }
    public function cancel(): void   { $this->update(['status' => self::STATUS_CANCELLED]); }

    public function isActive(): bool
    {
        return !in_array($this->status, [self::STATUS_ACCEPTED, self::STATUS_CANCELLED]);
    }

    public function isOverdue(): bool
    {
        return $this->resolved_date !== null
            && $this->resolved_date->toDateString() < $this->todayForOrganization()
            && $this->isActive()
            && !in_array($this->status, [self::STATUS_SUBMITTED, self::STATUS_ACCEPTED]);
    }

    public function daysFromToday(): ?int
    {
        if (!$this->resolved_date) {
            return null;
        }

        return (int) Carbon::parse($this->todayForOrganization())
            ->diffInDays(Carbon::parse($this->resolved_date->toDateString()), false);
    }

    /**
     * "Today" for this deliverable's organisation, as a plain Y-m-d string —
     * never the server's own UTC calendar day. `resolved_date` is a DATE
     * column with no time-of-day; comparing its own toDateString() against
     * this (rather than converting the DATE value's timezone) is what keeps
     * a date-only field from ever shifting — see TimezoneResolver.
     */
    private function todayForOrganization(): string
    {
        return TimezoneResolver::today(null, $this->organization)->toDateString();
    }
}
