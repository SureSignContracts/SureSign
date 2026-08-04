<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreConsultationReservationRequest;
use App\Models\ConsultancyPayment;
use App\Models\ConsultancyService;
use App\Models\ConsultancySlotReservation;
use App\Models\Organization;
use App\Services\Consultancy\ConsultancyBookingReadinessService;
use App\Services\Consultancy\ConsultancyCheckoutService;
use App\Services\Consultancy\ConsultancySlotReservationService;
use App\Services\TimezoneResolver;
use App\Support\Consultancy\ConsultancyPaymentPresenter;
use App\Support\Consultancy\ConsultancyReservationPresenter;
use Illuminate\Http\Request;

/**
 * Consultancy Live Booking Upgrade, Stage 2 — authenticated (Client/Admin/
 * Super Admin) temporary slot reservation. Deliberately separate from
 * PublicConsultancyReservationController (mirrors the existing Public/
 * Authenticated Consultancy controller split) — same underlying
 * ConsultancySlotReservationService, organisation-scoped ownership for
 * this flow instead of a bare public token.
 */
class ConsultationReservationController extends Controller
{
    public function __construct(
        private readonly ConsultancySlotReservationService $reservationService,
        private readonly ConsultancyCheckoutService $checkoutService,
        private readonly ConsultancyBookingReadinessService $readinessService,
    ) {
    }

    private function resolveBookableService(string $code): ConsultancyService
    {
        return ConsultancyService::where('code', $code)
            ->where('enabled', true)
            ->where('available_to_existing_customers', true)
            ->with('appointmentType')
            ->firstOrFail();
    }

    private function authorizeOwnReservation(Request $request, ConsultancySlotReservation $reservation): void
    {
        if ($reservation->organization_id !== $request->user()->organization_id) {
            abort(403, 'Access denied.');
        }
    }

    public function store(StoreConsultationReservationRequest $request, string $code)
    {
        $service = $this->resolveBookableService($code);
        $user = $request->user();
        $validated = $request->validated();

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
        $organization = Organization::find($user->organization_id);

        $existing = $this->reservationService->findActiveByAttemptToken($token);

        try {
            if ($existing && $existing->starts_at->equalTo($startsAt) && $existing->ends_at->equalTo($endsAt) && $existing->consultancy_service_id === $service->id) {
                $reservation = $existing;
            } elseif ($existing) {
                $reservation = $this->reservationService->replace($service, $startsAt, $endsAt, $attendee, $token, $user->organization_id, $user->id, $organization);
            } else {
                $reservation = $this->reservationService->reserve($service, $startsAt, $endsAt, $attendee, $token, $user->organization_id, $user->id, $organization);
            }
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(ConsultancyReservationPresenter::customerFacing($reservation), 201);
    }

    public function show(Request $request, string $token)
    {
        $reservation = $this->reservationService->findByPublicToken($token);
        if (!$reservation) {
            abort(404, 'Reservation not found.');
        }
        $this->authorizeOwnReservation($request, $reservation);

        return response()->json(ConsultancyReservationPresenter::customerFacing($reservation));
    }

    public function cancel(Request $request, string $token)
    {
        $reservation = $this->reservationService->findByPublicToken($token);
        if (!$reservation) {
            abort(404, 'Reservation not found.');
        }
        $this->authorizeOwnReservation($request, $reservation);

        $reservation = $this->reservationService->cancel($reservation, $request->user());

        return response()->json(ConsultancyReservationPresenter::customerFacing($reservation));
    }

    public function checkout(Request $request, string $token)
    {
        $reservation = $this->reservationService->findByPublicToken($token);
        if (!$reservation) {
            abort(404, 'Reservation not found.');
        }
        $this->authorizeOwnReservation($request, $reservation);

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

    public function paymentStatus(Request $request, string $token)
    {
        $reservation = $this->reservationService->findByPublicToken($token);
        if (!$reservation) {
            abort(404, 'Reservation not found.');
        }
        $this->authorizeOwnReservation($request, $reservation);

        $payment = ConsultancyPayment::where('reservation_id', $reservation->id)->latest('id')->first();
        if (!$payment) {
            return response()->json(['status' => 'not_started']);
        }

        return response()->json(ConsultancyPaymentPresenter::customerFacing($payment->load('appointment')));
    }
}
