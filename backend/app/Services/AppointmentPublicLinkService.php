<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\SuresignSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * Organisation URL Branding, Phase 1 note: every *MarketingUrl() method
 * below now routes its host through OrganisationUrlGenerator instead of
 * concatenating config('suresign.marketing_url') directly — an
 * organisation with a configured, valid url_slug (and platform URL
 * branding turned on — see config/organisation_branding.php) gets its own
 * branded hostname; every other organisation falls back to the exact same
 * default marketing host as before. The signed API URLs these wrap
 * (cancelApiUrl()/rescheduleApiUrl()/etc.) are completely unchanged — see
 * this class's own docblock below and
 * internal-docs/super-admin/organisation-url-branding.md's "API host
 * boundary" section for why that must never change.
 */

/**
 * Builds and validates the public cancel/reschedule links sent in
 * appointment emails.
 *
 * Signing is Laravel's own built-in signed-URL mechanism only — no custom
 * signature code. Each link is generated against a named API route keyed
 * on the appointment's opaque `public_token` (never the numeric id or the
 * sequential `reference`), with an expiry computed as the EARLIER of
 * "sent + configured TTL" and "appointment start − configured cutoff", so
 * a link can never outlive its practical usefulness even under a generous
 * TTL. Laravel's `signed` middleware validates the same way for both the
 * GET (view/confirm page) and POST (perform action) routes at the same
 * path — signature verification is URL-based, not HTTP-verb-based, so one
 * generated link is reused for both requests by the marketing page.
 *
 * `rescheduleSlotsApiUrl()` is signed too, via `signed:date` on its route —
 * every public endpoint stays signed, including the one that needs a
 * variable `date` query parameter (see that method's own doc).
 */
class AppointmentPublicLinkService
{
    public function __construct(
        private readonly OrganisationUrlGenerator $urlGenerator = new OrganisationUrlGenerator(),
    ) {
    }

    public function cancelApiUrl(Appointment $appointment): string
    {
        $settings = SuresignSetting::instance();

        return URL::temporarySignedRoute(
            'public.appointments.cancel',
            $this->expiryFor($appointment, $settings->appointment_cancel_link_ttl_hours, $settings->appointment_cancellation_cutoff_hours),
            ['token' => $appointment->public_token],
        );
    }

    public function rescheduleApiUrl(Appointment $appointment): string
    {
        $settings = SuresignSetting::instance();

        return URL::temporarySignedRoute(
            'public.appointments.reschedule',
            $this->expiryFor($appointment, $settings->appointment_reschedule_link_ttl_hours, $settings->appointment_reschedule_cutoff_hours),
            ['token' => $appointment->public_token],
        );
    }

    /**
     * Signed with NO `date` parameter — the route itself is protected by
     * `signed:date` (routes/api.php), which validates everything about the
     * signature except that one key. The frontend appends `&date=...`
     * freely as the visitor browses dates; Laravel ignores that specific
     * key when checking the signature, so the link stays fully signed
     * without needing a fresh signature per date. Same expiry policy as
     * the reschedule action link, since it's part of the same flow.
     */
    public function rescheduleSlotsApiUrl(Appointment $appointment): string
    {
        $settings = SuresignSetting::instance();

        return URL::temporarySignedRoute(
            'public.appointments.reschedule.slots',
            $this->expiryFor($appointment, $settings->appointment_reschedule_link_ttl_hours, $settings->appointment_reschedule_cutoff_hours),
            ['token' => $appointment->public_token],
        );
    }

    /**
     * Rewrites a signed API URL into the branded marketing-site page that
     * carries the identical query string (expires + signature) — the page
     * forwards those same params back to the API, so the signature Laravel
     * generated against the API route is exactly what gets validated.
     */
    public function cancelMarketingUrl(Appointment $appointment): string
    {
        return $this->toMarketingUrl($this->cancelApiUrl($appointment), $appointment, 'cancel');
    }

    public function rescheduleMarketingUrl(Appointment $appointment): string
    {
        return $this->toMarketingUrl($this->rescheduleApiUrl($appointment), $appointment, 'reschedule');
    }

    private function toMarketingUrl(string $signedApiUrl, Appointment $appointment, string $action): string
    {
        $query = parse_url($signedApiUrl, PHP_URL_QUERY) ?: '';

        return $this->urlGenerator->publicUrlWithRawQuery(
            $appointment->organization,
            "/appointments/{$appointment->public_token}",
            "action={$action}" . ($query !== '' ? "&{$query}" : ''),
        );
    }

    private function expiryFor(Appointment $appointment, int $ttlHours, int $cutoffHours): Carbon
    {
        $byTtl    = Carbon::now()->addHours($ttlHours);
        $byCutoff = $appointment->starts_at->copy()->subHours($cutoffHours);

        return $byTtl->lt($byCutoff) ? $byTtl : $byCutoff;
    }

    /**
     * Batch 3 (Consultancy Communications & Global Email Experience
     * Upgrade) — the public, no-account "view your consultation" page.
     * Deliberately NOT built on expiryFor() above: that formula anchors
     * expiry to starts_at - cutoffHours, which is only meaningful for an
     * action (cancel/reschedule) that must stop working once the
     * appointment cutoff passes. A read-only view link has no such
     * cutoff — it needs to keep working both before AND well after the
     * appointment happens — so this uses a flat TTL counted from now(),
     * via its own dedicated setting (consultation_public_link_ttl_hours),
     * never the cancel/reschedule TTLs. Still the exact same signing
     * mechanism (Laravel's own temporarySignedRoute) and the exact same
     * public_token — no second token system.
     */
    public function consultationViewApiUrl(Appointment $appointment): string
    {
        return URL::temporarySignedRoute(
            'public.consultations.view',
            Carbon::now()->addHours(SuresignSetting::instance()->consultation_public_link_ttl_hours),
            ['token' => $appointment->public_token],
        );
    }

    /**
     * The published-summary page — same reasoning and TTL as
     * consultationViewApiUrl() above (a summary, by definition, only
     * exists after the appointment is long over).
     */
    public function consultationSummaryApiUrl(Appointment $appointment): string
    {
        return URL::temporarySignedRoute(
            'public.consultations.summary',
            Carbon::now()->addHours(SuresignSetting::instance()->consultation_public_link_ttl_hours),
            ['token' => $appointment->public_token],
        );
    }

    /**
     * The ICS calendar-file download for the view page — same token,
     * same TTL policy, its own named route so the signature is scoped to
     * exactly this one action.
     */
    public function consultationViewIcsApiUrl(Appointment $appointment): string
    {
        return URL::temporarySignedRoute(
            'public.consultations.view.ics',
            Carbon::now()->addHours(SuresignSetting::instance()->consultation_public_link_ttl_hours),
            ['token' => $appointment->public_token],
        );
    }

    /**
     * The branded marketing-site page a customer actually clicks from an
     * email — mirrors cancelMarketingUrl()/rescheduleMarketingUrl()'s own
     * rewrite pattern exactly (same query string, same signature), at
     * `/consultations/{token}` rather than `/appointments/{token}` since
     * this is a Consultancy-only page with no generic-Appointment
     * equivalent.
     */
    public function consultationViewMarketingUrl(Appointment $appointment): string
    {
        $query = parse_url($this->consultationViewApiUrl($appointment), PHP_URL_QUERY) ?: '';

        return $this->urlGenerator->publicUrlWithRawQuery(
            $appointment->organization,
            "/consultations/{$appointment->public_token}",
            $query,
        );
    }

    public function consultationSummaryMarketingUrl(Appointment $appointment): string
    {
        $query = parse_url($this->consultationSummaryApiUrl($appointment), PHP_URL_QUERY) ?: '';

        return $this->urlGenerator->publicUrlWithRawQuery(
            $appointment->organization,
            "/consultations/{$appointment->public_token}/summary",
            $query,
        );
    }
}
