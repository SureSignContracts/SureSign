<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\SuresignSetting;
use App\Services\AppointmentIcsService;
use App\Services\AppointmentPublicLinkService;
use App\Support\Consultancy\ConsultationCommunicationLinks;
use App\Support\Consultancy\ConsultationMeetingPresenter;
use App\Support\Consultancy\ConsultationPresenter;
use App\Support\Organizations\EnforcesPublicOrganizationHost;
use Illuminate\Http\Request;

/**
 * Consultancy Communications & Global Email Experience Upgrade, Batch 3 —
 * the public, no-account "view your consultation" page (Scope A/B) and its
 * published-summary counterpart (Scope E). Both routes sit behind
 * Laravel's `signed` middleware (routes/api.php), keyed on the same
 * `Appointment::public_token` every other public Appointment route already
 * uses — see AppointmentPublicLinkService's own docblock for why the
 * expiry formula differs from cancel/reschedule.
 *
 * Deliberately a NEW, dedicated controller rather than extending
 * PublicAppointmentActionController: every route here is Consultancy-only
 * (a plain Appointment has no consultation_enquiry, no Meet, no summary),
 * so there is no `contextFor()`-style branching to share — mirrors the
 * existing precedent that ConsultationController/PublicConsultationController
 * are already separate from AppointmentController/PublicAppointmentController.
 *
 * Read-only: no route here mutates anything. Every response is built
 * exclusively from ConsultationPresenter's dedicated public whitelists
 * (never a raw Eloquent model) — no internal id, Google identifier,
 * provider identifier, or token is ever returned in a JSON body.
 */
class PublicConsultationViewController extends Controller
{
    use EnforcesPublicOrganizationHost;

    public function __construct(
        private readonly AppointmentPublicLinkService $linkService,
        private readonly ConsultationCommunicationLinks $links,
        private readonly AppointmentIcsService $icsService,
    ) {
    }

    /**
     * See App\Support\Organizations\EnforcesPublicOrganizationHost — when
     * the marketing frontend is served on a branded organisation hostname,
     * it forwards that context via the X-Suresign-Org-Host header; a
     * mismatch is treated identically to "not found".
     */
    private function findByToken(string $token): ?Appointment
    {
        $appointment = Appointment::where('public_token', $token)->first();

        // A public_token that resolves to a non-Consultancy Appointment is
        // deliberately treated exactly like "not found" — this controller
        // has nothing valid to show for it, and returning a distinct
        // response would confirm to a probing request that the token is
        // real but "the wrong kind," which is more than a 404 should ever
        // reveal.
        if (! $appointment || ! $appointment->consultationEnquiry) {
            return null;
        }

        if (! $this->hostMatchesOrganization(request()->header('X-Suresign-Org-Host'), $appointment->organization_id)) {
            return null;
        }

        return $appointment;
    }

    private function notFound()
    {
        return response()->json(['message' => 'This link is no longer valid.'], 404);
    }

    public function show(string $token)
    {
        $appointment = $this->findByToken($token);
        if (!$appointment) {
            return $this->notFound();
        }

        $appointment->loadMissing('appointmentType', 'assignedUser', 'consultationEnquiry.consultancyService', 'externalSync');
        $summaryPublished = $appointment->consultationEnquiry?->customer_summary_published !== null;

        return response()->json([
            ...ConsultationPresenter::publicView($appointment),
            'meeting'     => ConsultationMeetingPresenter::customerFacing($appointment),
            'ics_url'     => $this->icsAvailable($appointment) ? $this->linkService->consultationViewIcsApiUrl($appointment) : null,
            'summary_url' => $summaryPublished ? $this->linkService->consultationSummaryApiUrl($appointment) : null,
        ]);
    }

    public function ics(string $token)
    {
        $appointment = $this->findByToken($token);
        if (!$appointment || !$this->icsAvailable($appointment)) {
            return $this->notFound();
        }

        $appointment->loadMissing('appointmentType', 'assignedUser', 'externalSync');
        $joinUrl = $this->links->joinMeetUrl($appointment);

        return response($this->icsService->generate($appointment, $joinUrl), 200, [
            'Content-Type'        => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $this->icsService->filename($appointment) . '"',
        ]);
    }

    public function summary(string $token)
    {
        $appointment = $this->findByToken($token);
        if (!$appointment || $appointment->consultationEnquiry->customer_summary_published === null) {
            return $this->notFound();
        }

        $appointment->loadMissing('appointmentType', 'assignedUser', 'consultationEnquiry.consultancyService');

        return response()->json(ConsultationPresenter::publicSummary($appointment));
    }

    /**
     * A calendar invite only makes sense while the booking is still a
     * genuine future/current commitment — never for a cancelled/declined
     * appointment (nothing to add to a calendar), matching
     * AppointmentEmailService's own withIcs gating precedent.
     */
    private function icsAvailable(Appointment $appointment): bool
    {
        return !in_array($appointment->status, ['cancelled', 'declined'], true)
            && (bool) SuresignSetting::instance()->appointment_ics_enabled;
    }
}
