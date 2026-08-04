<?php

namespace App\Services\Consultancy;

use App\Models\ConsultancyService;
use App\Support\Appointments\AvailabilityContext;
use App\Services\AppointmentAvailabilityService;
use Illuminate\Support\Facades\Log;

/**
 * Consultancy Live Booking Upgrade, Stage 1 readiness check — a pure,
 * read-only classifier, never itself enforcing anything (mirrors
 * App\Support\AI\AiCreditReadinessGate's shape). This is deliberately NOT
 * the final paid-booking readiness gate from later stages — Stripe and
 * Google are never part of what this service checks, by design (Stage 1
 * excludes payment/calendar entirely).
 */
class ConsultancyBookingReadinessService
{
    public function __construct(
        private readonly ConsultancyConsultantResolver $consultantResolver,
        private readonly AppointmentAvailabilityService $availabilityService,
    ) {
    }

    /**
     * @return array{
     *     consultant_configured: bool,
     *     availability_configured: bool,
     *     active_service_available: bool,
     *     ready: bool,
     * }
     */
    public function check(): array
    {
        $consultant = $this->consultantResolver->resolve();

        $availabilityConfigured = $consultant !== null
            && $this->availabilityService->getWeeklySchedule($consultant, AvailabilityContext::CONSULTANCY)
                ->where('is_active', true)->isNotEmpty();

        $activeServiceAvailable = ConsultancyService::query()
            ->where('enabled', true)
            ->where(fn ($q) => $q->where('publicly_bookable', true)->orWhere('available_to_existing_customers', true))
            ->whereHas('appointmentType', fn ($q) => $q->where('is_active', true)->where('duration_minutes', '>', 0))
            ->exists();

        $consultantConfigured = $consultant !== null;

        return [
            'consultant_configured'     => $consultantConfigured,
            'availability_configured'   => $availabilityConfigured,
            'active_service_available'  => $activeServiceAvailable,
            'ready'                     => $consultantConfigured && $availabilityConfigured && $activeServiceAvailable,
        ];
    }

    /**
     * Consultancy Live Booking Activation Hardening — the single
     * customer-safe decision point every paid-booking checkout endpoint
     * must call before creating a Stripe Checkout Session. This is
     * deliberately a SEPARATE method from check() rather than a parameter/
     * flag on it: check()'s per-field breakdown is for Admin diagnostics
     * only (ConsultancySettingsController::readiness()) and must never
     * reach a customer response, so the two call sites are kept
     * structurally incapable of being confused.
     *
     * Distinguishes exactly two customer-facing categories, never which
     * specific configuration check failed:
     *
     *   - `temporarily_unavailable` — check() itself failed unexpectedly
     *     (e.g. a database error). A transient condition that may resolve
     *     on its own; the underlying exception is logged, never surfaced.
     *   - `configuration_unavailable` — check() ran successfully but
     *     reported the platform is not ready (no consultant configured, no
     *     availability, no active service). Requires operator action, but
     *     customers are never told which one.
     *
     * @return array{available: bool, reason_category: ?string, message: ?string}
     */
    public function checkoutAvailability(): array
    {
        try {
            $status = $this->check();
        } catch (\Throwable $e) {
            Log::error('ConsultancyBookingReadinessService: readiness check failed unexpectedly.', [
                'exception' => $e->getMessage(),
            ]);

            return [
                'available'       => false,
                'reason_category' => 'temporarily_unavailable',
                'message'         => 'Booking is temporarily unavailable. Please try again shortly.',
            ];
        }

        if ($status['ready']) {
            return ['available' => true, 'reason_category' => null, 'message' => null];
        }

        return [
            'available'       => false,
            'reason_category' => 'configuration_unavailable',
            'message'         => 'This booking option is not currently available. Please check back later or contact us.',
        ];
    }
}
