<?php

namespace App\Observers;

use App\Models\Appointment;
use App\Services\Consultancy\EngagementLifecycleService;

/**
 * The single, entry-point-agnostic sync point between Appointments-owned
 * scheduling cancellation and Consultancy-owned engagement_status — see
 * internal-docs/commercial/suresign-consultancy-phase-c2-specification-v1.md
 * §2.5. Registered on the `Appointment` model (AppServiceProvider::boot()),
 * never a change to AppointmentWorkflowService or any other Appointments
 * engine class.
 *
 * Fires on Eloquent's native `updated` event, so it uniformly covers every
 * real cancellation entry point — AppointmentController::cancel(),
 * ConsultationController::cancel(), and the public signed-link cancel —
 * without any of those three call sites needing to know Consultancy exists.
 * Depends on every cancellation path updating the Appointment through
 * Eloquent (confirmed true for all three today) — a raw query-builder
 * update bypassing the model would be missed; see the specification's
 * Risks section (§14).
 */
class ConsultancyAppointmentObserver
{
    public function __construct(
        private readonly EngagementLifecycleService $engagementLifecycleService,
    ) {
    }

    public function updated(Appointment $appointment): void
    {
        if (!$appointment->wasChanged('status') || $appointment->status !== 'cancelled') {
            return;
        }

        $enquiry = $appointment->consultationEnquiry;
        if (!$enquiry) {
            return;
        }

        $this->engagementLifecycleService->syncFromAppointmentCancellation($enquiry);
    }
}
