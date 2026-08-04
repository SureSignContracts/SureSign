<?php

namespace App\Support\Consultancy;

use App\Models\Appointment;
use App\Services\AppointmentEmailService;
use App\Services\AppointmentPublicLinkService;

/**
 * Consultancy Communications & Global Email Experience Upgrade, Batch 1 —
 * the single authoritative resolver for every customer-safe action link a
 * Consultancy communication (email or, later, a public page) needs. No
 * template/service should rebuild this routing logic independently — see
 * the approved architecture's "Action-Link Resolver" requirement.
 *
 * Routing signal: `Appointment::linked_user_id` (set only by the
 * authenticated booking flow — App\Services\Consultancy\ConsultationEnquiryService::book())
 * distinguishes an authenticated customer (in-app destination) from a
 * public/no-account customer (existing signed marketing-site destinations).
 *
 * Public-customer "manage" destination: Batch 1 found no dedicated
 * no-action public view page yet, and resolved `manageUrl()` for a public
 * recipient to whichever of the reschedule/cancel confirmation pages was
 * currently eligible, falling back to null when neither was. Batch 3 built
 * that dedicated view-only page (viewUrl(), below) — `manageUrl()` now
 * falls back to it instead of null, so a public customer whose booking is
 * no longer reschedulable/cancellable (completed, past cutoff) still gets
 * a real destination.
 *
 * Join Google Meet never routes through this resolver's own link-building —
 * it is always the trusted, provider-normalised `meeting_join_url` already
 * stored on `AppointmentExternalSync` (see ConsultationMeetingPresenter),
 * passed in directly by the caller. This class only ever returns
 * SureSign-owned URLs.
 */
class ConsultationCommunicationLinks
{
    public function __construct(
        private readonly AppointmentEmailService $emailService,
        private readonly AppointmentPublicLinkService $linkService,
    ) {
    }

    public function isAuthenticated(Appointment $appointment): bool
    {
        return $appointment->linked_user_id !== null;
    }

    /**
     * The primary "go look at your booking" destination — never null for
     * an authenticated customer; may be null for a public customer when
     * neither reschedule nor cancellation is currently eligible.
     */
    public function manageUrl(Appointment $appointment): ?string
    {
        if ($this->isAuthenticated($appointment)) {
            // Organisation URL Branding, Phase 1: the authenticated app
            // frontend (frontend_url) is intentionally left on its fixed,
            // unbranded host this phase — see
            // internal-docs/super-admin/organisation-url-branding.md's
            // "call sites intentionally left unchanged" section.
            return rtrim(config('suresign.frontend_url'), '/') . "/app/consultations/{$appointment->id}";
        }

        if ($this->emailService->isReschedulable($appointment)) {
            return $this->linkService->rescheduleMarketingUrl($appointment);
        }

        if ($this->emailService->isCancellable($appointment)) {
            return $this->linkService->cancelMarketingUrl($appointment);
        }

        // Batch 3 closes the gap this class's own docblock flagged: a
        // public customer whose booking is no longer reschedulable/
        // cancellable (e.g. completed, or past the cutoff) previously got
        // no "manage" destination at all. The dedicated view-only page now
        // covers that case.
        return $this->viewUrl($appointment);
    }

    /**
     * Batch 3 — the public, no-account "view your consultation" page
     * (status, schedule, Meet join, calendar download, and a link to the
     * published summary once one exists). Always returns a URL, unlike
     * manageUrl()/rescheduleUrl()/cancelUrl() above, which can legitimately
     * be null when the underlying action isn't available — viewing is
     * never gated the same way.
     */
    public function viewUrl(Appointment $appointment): string
    {
        return $this->linkService->consultationViewMarketingUrl($appointment);
    }

    /**
     * Null until a summary genuinely exists — never a placeholder/pending
     * link a customer could click before there's anything to read.
     */
    public function summaryUrl(Appointment $appointment): ?string
    {
        if ($appointment->consultationEnquiry?->customer_summary_published === null) {
            return null;
        }

        return $this->linkService->consultationSummaryMarketingUrl($appointment);
    }

    public function rescheduleUrl(Appointment $appointment): ?string
    {
        if (!$this->emailService->isReschedulable($appointment)) {
            return null;
        }

        // Authenticated customers reschedule from within the app today
        // (no in-app reschedule action exists yet for Consultancy — this
        // mirrors the existing gap rather than inventing one) — fall back
        // to the same signed marketing link either way, exactly as
        // AppointmentEmailService::appendManageLinks() already does for
        // every other Appointment type.
        return $this->linkService->rescheduleMarketingUrl($appointment);
    }

    public function cancelUrl(Appointment $appointment): ?string
    {
        if (!$this->emailService->isCancellable($appointment)) {
            return null;
        }

        return $this->linkService->cancelMarketingUrl($appointment);
    }

    /**
     * The trusted Meet join URL, only when the sync row genuinely reports
     * it joinable — never a fallback/placeholder. Returns null otherwise
     * (pending/unavailable/not a Consultancy appointment/no sync row yet).
     */
    public function joinMeetUrl(Appointment $appointment): ?string
    {
        $sync = $appointment->externalSync;

        if (!$sync || !$sync->isMeetingJoinable()) {
            return null;
        }

        return $sync->meeting_join_url;
    }
}
