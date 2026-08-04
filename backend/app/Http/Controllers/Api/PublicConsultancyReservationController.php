<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePublicConsultancyReservationRequest;
use App\Models\ConsultancyPayment;
use App\Models\ConsultancyService;
use App\Services\Consultancy\ConsultancyBookingReadinessService;
use App\Services\Consultancy\ConsultancyCheckoutService;
use App\Services\Consultancy\ConsultancySlotReservationService;
use App\Services\TimezoneResolver;
use App\Support\Consultancy\ConsultancyPaymentPresenter;
use App\Support\Consultancy\ConsultancyReservationPresenter;
use Illuminate\Http\Request;

/**
 * Consultancy Live Booking Upgrade, Stage 2 — public temporary slot
 * reservation. Explicitly stops short of Stripe: this controller creates
 * and manages a hold only, never a payment, never a confirmed booking.
 * Same security posture as PublicConsultationController: generic 404 for
 * any non-bookable/nonexistent service code, no consultant identity or
 * internal data ever returned, rate-limited in routes/api.php.
 */
class PublicConsultancyReservationController extends Controller
{
    public function __construct(
        private readonly ConsultancySlotReservationService $reservationService,
        private readonly ConsultancyCheckoutService $checkoutService,
        private readonly ConsultancyBookingReadinessService $readinessService,
    ) {
    }

    private function findPublicService(string $code): ?ConsultancyService
    {
        return ConsultancyService::where('code', $code)
            ->where('enabled', true)
            ->where('publicly_bookable', true)
            ->with('appointmentType')
            ->first();
    }

    private function notFound()
    {
        return response()->json(['message' => 'This booking page is not available.'], 404);
    }

    /**
     * Creates a reservation, or — if the same booking_attempt_token
     * already holds a different active reservation — atomically replaces
     * it (see ConsultancySlotReservationService::replace()'s own
     * docblock). A request for the SAME slot with the SAME token is a
     * safe idempotent no-op, returning the existing reservation.
     */
    public function store(StorePublicConsultancyReservationRequest $request, string $code)
    {
        $service = $this->findPublicService($code);
        if (!$service || !$service->appointmentType->is_active) {
            return $this->notFound();
        }

        $validated = $request->validated();

        if (!empty($validated['website'])) {
            return response()->json(['message' => 'Received.'], 201);
        }

        try {
            $startsAt = TimezoneResolver::buildLocalInstant($validated['date'], $validated['start_time'], $validated['timezone']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $endsAt = $startsAt->copy()->addMinutes($service->appointmentType->duration_minutes);

        $attendee = [
            'name'     => $validated['attendee_name'],
            'email'    => $validated['attendee_email'],
            'timezone' => $validated['attendee_timezone'],
        ];
        $token = $validated['booking_attempt_token'];

        $existing = $this->reservationService->findActiveByAttemptToken($token);

        try {
            if ($existing && $existing->starts_at->equalTo($startsAt) && $existing->ends_at->equalTo($endsAt) && $existing->consultancy_service_id === $service->id) {
                $reservation = $existing;
            } elseif ($existing) {
                $reservation = $this->reservationService->replace($service, $startsAt, $endsAt, $attendee, $token);
            } else {
                $reservation = $this->reservationService->reserve($service, $startsAt, $endsAt, $attendee, $token);
            }
        } catch (\RuntimeException $e) {
            return response()->json(['message' => 'That time is no longer available — please choose another.'], 409);
        }

        return response()->json(ConsultancyReservationPresenter::customerFacing($reservation), 201);
    }

    public function show(string $token)
    {
        $reservation = $this->reservationService->findByPublicToken($token);
        if (!$reservation) {
            return $this->notFound();
        }

        return response()->json(ConsultancyReservationPresenter::customerFacing($reservation));
    }

    public function cancel(Request $request, string $token)
    {
        $reservation = $this->reservationService->findByPublicToken($token);
        if (!$reservation) {
            return $this->notFound();
        }

        $reservation = $this->reservationService->cancel($reservation);

        return response()->json(ConsultancyReservationPresenter::customerFacing($reservation));
    }

    /**
     * Consultancy Live Booking Upgrade, Stage 3 — creates (or idempotently
     * reuses) the Stripe Checkout Session for this reservation. The
     * reservation's own public_token is embedded in the success/cancel
     * URLs so the customer-facing success page can poll paymentStatus()
     * below — never a Stripe identifier, and the token is never
     * authoritative for anything (see ConsultancyPaymentPresenter).
     *
     * Consultancy Live Booking Activation Hardening — a Checkout Session
     * is never created unless ConsultancyBookingReadinessService reports
     * the platform is ready to deliver the booked consultation. The 503
     * response is deliberately customer-safe (see that service's
     * checkoutAvailability() docblock) — it never reveals which specific
     * configuration check failed.
     */
    public function checkout(Request $request, string $token)
    {
        $reservation = $this->reservationService->findByPublicToken($token);
        if (!$reservation) {
            return $this->notFound();
        }

        $availability = $this->readinessService->checkoutAvailability();
        if (!$availability['available']) {
            return response()->json([
                'message' => $availability['message'],
                'reason'  => $availability['reason_category'],
            ], 503);
        }

        $successUrl = rtrim((string) config('consultancy.checkout_success_url'), '/') . '?reservation=' . urlencode($token);
        $cancelUrl = rtrim((string) config('consultancy.checkout_cancel_url'), '/') . '?reservation=' . urlencode($token);

        try {
            $payment = $this->checkoutService->createCheckoutSession($reservation, $successUrl, $cancelUrl);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json([
            'checkout_url' => $payment->checkout_url,
            'expires_at'   => $payment->checkout_expires_at?->toIso8601String(),
            'status'       => ConsultancyPaymentPresenter::customerFacing($payment)['status'],
        ], 201);
    }

    /**
     * The success page must poll THIS endpoint for authoritative status —
     * never treat the browser's own arrival at the success URL as proof of
     * payment (the verified Stripe webhook is the only source of truth).
     */
    public function paymentStatus(string $token)
    {
        $reservation = $this->reservationService->findByPublicToken($token);
        if (!$reservation) {
            return $this->notFound();
        }

        $payment = ConsultancyPayment::where('reservation_id', $reservation->id)->latest('id')->first();
        if (!$payment) {
            return response()->json(['status' => 'not_started']);
        }

        return response()->json(ConsultancyPaymentPresenter::customerFacing($payment->load('appointment')));
    }
}
