<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Temporary Slot Reservation
    |--------------------------------------------------------------------------
    |
    | How long a Consultancy slot reservation (App\Models\ConsultancySlotReservation)
    | stays 'active' before it stops blocking the slot. A configuration
    | value rather than a scattered magic number — see
    | App\Services\Consultancy\ConsultancySlotReservationService.
    */
    'reservation_hold_minutes' => (int) env('CONSULTANCY_RESERVATION_HOLD_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | Stripe Checkout (Stage 3)
    |--------------------------------------------------------------------------
    |
    | Stripe requires a Checkout Session expiry between 30 minutes and 24
    | hours — 30 is the approved launch value. Once Checkout is created,
    | the reservation's own expires_at is extended to match this exact
    | value (see App\Services\Consultancy\ConsultancyCheckoutService) —
    | reservation.expires_at, consultancy_payments.checkout_expires_at, and
    | the real Stripe Session's own expires_at are always kept identical.
    */
    'checkout_expiry_minutes' => (int) env('CONSULTANCY_CHECKOUT_EXPIRY_MINUTES', 30),

    // Deliberately separate from billing.checkout_success_url/cancel_url —
    // Consultancy payments are not subscription billing (see root
    // CLAUDE.md's "Consultancy payments are separate from... invoice-based
    // SaaS billing").
    'checkout_success_url' => env('CONSULTANCY_CHECKOUT_SUCCESS_URL', ''),
    'checkout_cancel_url'  => env('CONSULTANCY_CHECKOUT_CANCEL_URL', ''),

];
