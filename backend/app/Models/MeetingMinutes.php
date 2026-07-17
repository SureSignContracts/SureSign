<?php

namespace App\Models;

use App\Services\TimezoneResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingMinutes extends Model
{
    protected $fillable = [
        'project_id', 'organization_id', 'created_by',
        'meeting_number', 'title', 'meeting_date', 'location', 'type',
        'starts_at', 'ends_at', 'scheduled_timezone',
        'attendees', 'agenda', 'minutes', 'action_items', 'status',
    ];

    protected $casts = [
        'meeting_date' => 'date',
        'starts_at'    => 'datetime',
        'ends_at'      => 'datetime',
        'attendees'    => 'array',
        'action_items' => 'array',
    ];

    protected $appends = ['is_timed'];

    /**
     * Batch 6 invariant: `meeting_date` is always kept in sync with
     * `starts_at`, never left for the frontend/caller to maintain.
     *
     * Whenever `starts_at` is set to a real instant, `meeting_date` is
     * derived from that instant's calendar day in the meeting's own
     * `scheduled_timezone` (falling back to the organisation's effective
     * timezone if that's somehow missing) — converting a genuine UTC
     * DATETIME's timezone to find its local calendar day is the correct
     * thing to do here (unlike doing that to a DATE-only value, which
     * would risk shifting it).
     *
     * When `starts_at` is explicitly cleared (converting a timed meeting
     * back to date-only), this does nothing — the caller (controller) is
     * responsible for supplying the new authoritative `meeting_date`
     * explicitly, since that's a deliberate mode switch, not a derivation.
     */
    protected static function booted(): void
    {
        static::saving(function (MeetingMinutes $meeting) {
            if ($meeting->isDirty('starts_at') && $meeting->starts_at) {
                $timezone = $meeting->scheduled_timezone
                    ?? TimezoneResolver::effectiveTimezone(null, $meeting->organization);

                $meeting->meeting_date = $meeting->starts_at->copy()->setTimezone($timezone)->toDateString();
            }
        });
    }

    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }

    public function getIsTimedAttribute(): bool
    {
        return $this->starts_at !== null;
    }
}
