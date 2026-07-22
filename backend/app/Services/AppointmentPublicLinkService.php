<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\SuresignSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\URL;

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
        $base  = rtrim(config('suresign.marketing_url'), '/');

        return "{$base}/appointments/{$appointment->public_token}?action={$action}&{$query}";
    }

    private function expiryFor(Appointment $appointment, int $ttlHours, int $cutoffHours): Carbon
    {
        $byTtl    = Carbon::now()->addHours($ttlHours);
        $byCutoff = $appointment->starts_at->copy()->subHours($cutoffHours);

        return $byTtl->lt($byCutoff) ? $byTtl : $byCutoff;
    }
}
