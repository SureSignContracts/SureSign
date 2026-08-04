<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A temporary, server-authoritative hold on one Consultancy slot for one
 * customer booking attempt — Consultancy Live Booking Upgrade, Stage 2.
 *
 * This is NOT an Appointment, NOT a payment, NOT a Consultation Engagement,
 * and NOT a Google Calendar event. It exists solely to protect a slot
 * between "customer selected a time" and (in a later stage) "payment
 * confirmed" — see App\Services\Consultancy\ConsultancySlotReservationService
 * for the full lifecycle.
 *
 * Only a row with status='active' AND expires_at in the future blocks a
 * slot — see AppointmentSchedulingService::isSlotFree()/hasBufferedConflict(),
 * which query on both conditions directly (never status alone), so an
 * elapsed reservation stops blocking immediately even before the
 * `consultancy:reservations:expire` scheduled command marks it 'expired'.
 */
class ConsultancySlotReservation extends Model
{
    public const STATUSES = ['active', 'consumed', 'expired', 'cancelled'];

    /**
     * The only transitions this model's lifecycle may take — enforced by
     * App\Services\Consultancy\ConsultancySlotReservationService, never by
     * a controller directly. Every terminal state (consumed/expired/
     * cancelled) has no further transition — a terminal reservation never
     * becomes active again.
     */
    public const TRANSITIONS = [
        'active'    => ['consumed', 'expired', 'cancelled'],
        'consumed'  => [],
        'expired'   => [],
        'cancelled' => [],
    ];

    protected $fillable = [
        'public_token', 'booking_attempt_token', 'active_attempt_token',
        'consultancy_service_id', 'consultant_user_id', 'organization_id', 'linked_user_id',
        'attendee_name', 'attendee_email',
        'starts_at', 'ends_at', 'booking_timezone',
        'status', 'expires_at', 'consumed_at', 'cancelled_at',
    ];

    protected $casts = [
        'starts_at'    => 'datetime',
        'ends_at'      => 'datetime',
        'expires_at'   => 'datetime',
        'consumed_at'  => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (ConsultancySlotReservation $reservation) {
            $reservation->public_token ??= Str::random(48);
        });
    }

    public function consultancyService(): BelongsTo { return $this->belongsTo(ConsultancyService::class); }
    public function consultant(): BelongsTo         { return $this->belongsTo(User::class, 'consultant_user_id'); }
    public function organization(): BelongsTo       { return $this->belongsTo(Organization::class); }
    public function linkedUser(): BelongsTo         { return $this->belongsTo(User::class, 'linked_user_id'); }

    public function isActiveAndUnexpired(): bool
    {
        return $this->status === 'active' && $this->expires_at->isFuture();
    }
}
