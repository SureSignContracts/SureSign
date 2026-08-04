<?php

namespace App\Support\Consultancy;

use App\Models\ConsultancySlotReservation;

/**
 * Consultancy Live Booking Upgrade, Stage 2 — the single place a
 * ConsultancySlotReservation is shaped for a customer-facing response.
 * Mirrors App\Support\AI\AiAnalysisPresenter/App\Support\Billing\BillingPresenter's
 * existing discipline: never the raw Eloquent model, never the database
 * ID, never the consultant's identity, never the availability context.
 */
class ConsultancyReservationPresenter
{
    public static function customerFacing(ConsultancySlotReservation $reservation): array
    {
        return [
            'token'         => $reservation->public_token,
            'status'        => $reservation->status,
            'starts_at'     => $reservation->starts_at->toIso8601String(),
            'ends_at'       => $reservation->ends_at->toIso8601String(),
            'timezone'      => $reservation->booking_timezone,
            'expires_at'    => $reservation->expires_at->toIso8601String(),
            'service'       => [
                'code'         => $reservation->consultancyService->code,
                'display_name' => $reservation->consultancyService->display_name,
            ],
        ];
    }
}
