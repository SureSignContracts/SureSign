<?php

namespace App\Models;

use App\Services\TimezoneResolver;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CalendarEvent extends Model
{
    use SoftDeletes;

    // Source types — every calendar event originates from a real record
    public const SOURCE_CONTRACT_DEADLINE   = 'contract_deadline';
    public const SOURCE_CONTRACT_NOTICE     = 'contract_notice';
    public const SOURCE_CONTRACT_DELIVERABLE = 'contract_deliverable';
    public const SOURCE_PAYMENT_APPLICATION = 'payment_application';
    public const SOURCE_PAYMENT_NOTICE      = 'payment_notice';
    public const SOURCE_PAY_LESS_NOTICE     = 'pay_less_notice';
    public const SOURCE_RETENTION_RELEASE   = 'retention_release';
    public const SOURCE_PROGRAMME_MILESTONE = 'programme_milestone';
    public const SOURCE_VARIATION           = 'variation';
    public const SOURCE_CONTRACT            = 'contract';
    public const SOURCE_FINAL_ACCOUNT       = 'final_account';
    public const SOURCE_DELAY_EVENT         = 'delay_event';
    public const SOURCE_EOT_REQUEST         = 'eot_request';
    public const SOURCE_LOSS_AND_EXPENSE    = 'loss_and_expense_claim';
    public const SOURCE_CONTRACT_RISK       = 'contract_risk';
    public const SOURCE_DELIVERY_DOCUMENT   = 'delivery_document';
    public const SOURCE_RFI                 = 'rfi';

    // Categories
    public const CATEGORY_COMMERCIAL   = 'commercial';
    public const CATEGORY_PROGRAMME    = 'programme';
    public const CATEGORY_CONTRACT     = 'contract';
    public const CATEGORY_COMPLIANCE   = 'compliance';
    public const CATEGORY_PAYMENT      = 'payment';
    public const CATEGORY_RETENTION    = 'retention';
    public const CATEGORY_RISK         = 'risk';
    public const CATEGORY_DELIVERABLES = 'deliverables';
    public const CATEGORY_NOTICES      = 'notices';
    public const CATEGORY_COMMUNICATION = 'communication';

    // Statuses
    public const STATUS_PENDING   = 'pending';
    public const STATUS_UPCOMING  = 'upcoming';
    public const STATUS_DUE_TODAY = 'due_today';
    public const STATUS_OVERDUE   = 'overdue';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_MISSED    = 'missed';
    public const STATUS_CANCELLED = 'cancelled';

    // Priorities
    public const PRIORITY_LOW      = 'low';
    public const PRIORITY_MEDIUM   = 'medium';
    public const PRIORITY_HIGH     = 'high';
    public const PRIORITY_CRITICAL = 'critical';

    protected $fillable = [
        'organization_id', 'project_id', 'contract_id',
        'source_type', 'source_id', 'source_field',
        'title', 'description', 'category',
        'event_date', 'due_date',
        'status', 'priority',
        'is_recurring', 'recurrence_rule',
        'generated_by_ai', 'generated_from_contract',
        'metadata',
    ];

    protected $casts = [
        'event_date'               => 'date',
        'due_date'                 => 'date',
        'is_recurring'             => 'boolean',
        'generated_by_ai'          => 'boolean',
        'generated_from_contract'  => 'boolean',
        'metadata'                 => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isDueToday(): bool
    {
        return $this->event_date?->toDateString() === $this->todayForOrganization();
    }

    public function isOverdue(): bool
    {
        return $this->event_date !== null && $this->event_date->toDateString() < $this->todayForOrganization();
    }

    public function daysFromToday(): ?int
    {
        if (!$this->event_date) {
            return null;
        }

        return (int) Carbon::parse($this->todayForOrganization())
            ->diffInDays(Carbon::parse($this->event_date->toDateString()), false);
    }

    /**
     * "Today" for this event's organisation, as a plain Y-m-d string —
     * never the server's own UTC calendar day. `event_date` is a DATE
     * column with no time-of-day; comparing its own toDateString() against
     * this (rather than converting the DATE value's timezone) is what keeps
     * a date-only field from ever shifting — see TimezoneResolver.
     */
    private function todayForOrganization(): string
    {
        return TimezoneResolver::today(null, $this->organization)->toDateString();
    }

    /**
     * Recompute and persist the status based on event_date and today.
     */
    public function refreshStatus(): void
    {
        $days = $this->daysFromToday();

        if ($days === null || in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_MISSED, self::STATUS_CANCELLED])) {
            return;
        }

        $this->status = match (true) {
            $days < 0  => self::STATUS_OVERDUE,
            $days === 0 => self::STATUS_DUE_TODAY,
            $days <= 30 => self::STATUS_UPCOMING,
            default     => self::STATUS_PENDING,
        };

        $this->save();
    }

    /**
     * Compute display status (upcoming|due_today|overdue|pending|unscheduled)
     * from days remaining. Single source of truth — shared by
     * OperationalIntelligenceService's collectors and any live-computed
     * CalendarController section that isn't sourced from a collector.
     */
    public static function computeStatusFromDays(?int $daysFromToday): string
    {
        if ($daysFromToday === null) return 'unscheduled';

        return match (true) {
            $daysFromToday < 0  => 'overdue',
            $daysFromToday === 0 => 'due_today',
            $daysFromToday <= 30 => 'upcoming',
            default              => 'pending',
        };
    }

    /**
     * Compute priority from days remaining and category.
     */
    public static function computePriority(?int $daysFromToday, string $category): string
    {
        if ($daysFromToday === null) return self::PRIORITY_MEDIUM;

        // Payment and statutory notices escalate faster
        $isUrgentCategory = in_array($category, [self::CATEGORY_PAYMENT, self::CATEGORY_NOTICES, self::CATEGORY_COMPLIANCE]);

        return match (true) {
            $daysFromToday < 0  => self::PRIORITY_CRITICAL,
            $daysFromToday === 0 => $isUrgentCategory ? self::PRIORITY_CRITICAL : self::PRIORITY_HIGH,
            $daysFromToday <= 3  => self::PRIORITY_HIGH,
            $daysFromToday <= 7  => $isUrgentCategory ? self::PRIORITY_HIGH : self::PRIORITY_MEDIUM,
            $daysFromToday <= 14 => self::PRIORITY_MEDIUM,
            default              => self::PRIORITY_LOW,
        };
    }
}
