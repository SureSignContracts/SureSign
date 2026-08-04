<?php

namespace App\Support\Consultancy;

use App\Models\ConsultancyPayment;

/**
 * Consultancy Live Booking Upgrade, Stage 3 — the single place a
 * ConsultancyPayment is shaped for a customer-facing response. Never the
 * consultant's identity, never Stripe identifiers, never internal IDs, and
 * never a raw webhook payload — mirrors
 * App\Support\Consultancy\ConsultancyReservationPresenter's identical
 * discipline.
 *
 * The success page must treat `status` as the only source of truth and
 * never assume 'paid'/'converted' merely because the customer reached this
 * page — see App\Http\Controllers\Api\PublicConsultancyReservationController::paymentStatus().
 */
class ConsultancyPaymentPresenter
{
    public static function customerFacing(ConsultancyPayment $payment): array
    {
        return [
            'status'         => self::customerFacingStatus($payment->status),
            'service'        => [
                'code'         => $payment->service_code_snapshot,
                'display_name' => $payment->service_name_snapshot,
            ],
            'amount_minor_units' => $payment->total_minor_units,
            'currency'           => $payment->currency,
            'starts_at'          => $payment->starts_at_snapshot->toIso8601String(),
            'ends_at'            => $payment->ends_at_snapshot->toIso8601String(),
            'timezone'           => $payment->booking_timezone_snapshot,
            'appointment_reference' => $payment->status === 'converted' ? $payment->appointment?->reference : null,
        ];
    }

    /**
     * Collapses internal-only statuses ('conversion_pending',
     * 'manual_review') into a single safe customer-facing 'processing'
     * value — the customer never needs to know an internal recovery state
     * exists; the success page should simply keep showing "processing"
     * until it resolves to 'converted' or a clear failure state.
     */
    private static function customerFacingStatus(string $status): string
    {
        return match ($status) {
            'conversion_pending', 'manual_review' => 'processing',
            default => $status,
        };
    }
}
