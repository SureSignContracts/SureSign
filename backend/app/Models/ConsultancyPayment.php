<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Consultancy Live Booking Upgrade, Stage 3 — the authoritative owner of
 * the immutable commercial snapshot and the payment lifecycle for one
 * Consultancy booking attempt. See
 * internal-docs/super-admin/consultancy.md's Stage 3 section for the full
 * "why the payment, not the reservation, owns the snapshot" rationale.
 *
 * Every `*_snapshot` column is frozen at Checkout-creation time and must
 * never be re-derived from the live ConsultancyService/AppointmentType
 * afterwards — see App\Services\Consultancy\ConsultancyCheckoutService,
 * the only place these columns are ever written.
 */
class ConsultancyPayment extends Model
{
    public const STATUSES = [
        'creating', 'checkout_open', 'paid', 'conversion_pending',
        'converted', 'expired', 'cancelled', 'failed', 'manual_review',
    ];

    /**
     * `paid` and `converted` are deliberately distinct and never collapsed
     * — a payment may be financially successful (Stripe has been paid)
     * while local Appointment conversion still needs retry/operator
     * recovery (`conversion_pending`/`manual_review`). See
     * App\Services\Consultancy\ConsultancyPaymentConversionService.
     */
    public const TRANSITIONS = [
        'creating'           => ['checkout_open', 'failed'],
        'checkout_open'      => ['paid', 'expired', 'cancelled', 'failed'],
        'paid'               => ['conversion_pending', 'converted'],
        'conversion_pending' => ['converted', 'manual_review'],
        'manual_review'      => ['converted'],
        'converted'          => [],
        'expired'            => [],
        'cancelled'          => [],
        'failed'             => [],
    ];

    protected $fillable = [
        'reservation_id', 'consultancy_service_id',
        'service_code_snapshot', 'service_name_snapshot', 'description_snapshot',
        'consultant_user_id_snapshot', 'duration_minutes_snapshot',
        'starts_at_snapshot', 'ends_at_snapshot', 'booking_timezone_snapshot',
        'amount_minor_units', 'currency', 'tax_treatment',
        'subtotal_minor_units', 'tax_minor_units', 'total_minor_units',
        'attendee_name_snapshot', 'attendee_email_snapshot',
        'organization_id', 'linked_user_id', 'booking_attempt_token',
        'status', 'provider', 'livemode',
        'stripe_checkout_session_id', 'checkout_url', 'stripe_payment_intent_id', 'checkout_expires_at',
        'confirming_stripe_event_id', 'appointment_id',
        'paid_at', 'failed_at', 'cancelled_at', 'converted_at',
    ];

    protected $casts = [
        'starts_at_snapshot'  => 'datetime',
        'ends_at_snapshot'    => 'datetime',
        'checkout_expires_at' => 'datetime',
        'paid_at'             => 'datetime',
        'failed_at'           => 'datetime',
        'cancelled_at'        => 'datetime',
        'converted_at'        => 'datetime',
        'livemode'            => 'boolean',
    ];

    public function reservation(): BelongsTo      { return $this->belongsTo(ConsultancySlotReservation::class, 'reservation_id'); }
    public function consultancyService(): BelongsTo { return $this->belongsTo(ConsultancyService::class); }
    public function consultant(): BelongsTo       { return $this->belongsTo(User::class, 'consultant_user_id_snapshot'); }
    public function organization(): BelongsTo     { return $this->belongsTo(Organization::class); }
    public function linkedUser(): BelongsTo       { return $this->belongsTo(User::class, 'linked_user_id'); }
    public function appointment(): BelongsTo      { return $this->belongsTo(Appointment::class); }

    public function isPaidOrBeyond(): bool
    {
        return in_array($this->status, ['paid', 'conversion_pending', 'converted', 'manual_review'], true);
    }
}
