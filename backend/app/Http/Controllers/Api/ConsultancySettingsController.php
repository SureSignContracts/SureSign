<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateConsultancyConsultantRequest;
use App\Http\Requests\UpdateConsultancyNotificationSettingsRequest;
use App\Models\ActivityLog;
use App\Models\ConsultancyPayment;
use App\Models\ConsultancySlotReservation;
use App\Models\SuresignSetting;
use App\Models\User;
use App\Services\Consultancy\ConsultancyBookingReadinessService;
use App\Services\Consultancy\ConsultancyConsultantResolver;
use App\Services\Consultancy\ConsultancyPaymentConversionService;
use App\Services\Consultancy\ConsultancySlotReservationService;
use App\Services\Consultancy\Exceptions\ConsultancyConversionRetryableException;
use App\Services\Consultancy\Exceptions\ConsultancyManualReviewRequiredException;
use App\Support\Consultancy\ConsultancyNewBookingNotificationRecipients;
use Illuminate\Http\Request;

/**
 * Consultancy Live Booking Upgrade, Stage 1 — the Consultancy consultant
 * configuration surface. Read: Super Admin or Admin (platform-wide roles,
 * matching this codebase's existing convention for Consultancy read
 * access). Write: Super Admin only (a platform-wide operational setting,
 * mirroring App\Http\Requests\UpdateAiCreditOperatingModeRequest's own
 * Super-Admin-only convention for a comparably consequential toggle).
 */
class ConsultancySettingsController extends Controller
{
    public function __construct(
        private readonly ConsultancyConsultantResolver $consultantResolver,
        private readonly ConsultancyBookingReadinessService $readinessService,
        private readonly ConsultancySlotReservationService $reservationService,
        private readonly ConsultancyPaymentConversionService $conversionService,
    ) {
    }

    private function requireSuperAdmin(Request $request): void
    {
        if (!$request->user()->hasRole('Super Admin')) {
            abort(403, 'Access denied.');
        }
    }

    public function show(Request $request)
    {
        $consultant = $this->consultantResolver->resolve();
        $configuredUserId = SuresignSetting::instance()->consultancy_consultant_user_id;

        return response()->json([
            'consultant' => $consultant ? [
                'id'    => $consultant->id,
                'name'  => $consultant->name,
                'email' => $consultant->email,
            ] : null,
            // True when a user_id is stored but that user no longer passes
            // eligibility (inactive/banned/role changed) — distinct from
            // "nothing configured at all", surfaced so an operator can tell
            // the two apart rather than seeing an identical "not ready".
            'configured_but_ineligible' => $configuredUserId !== null && $consultant === null,
        ]);
    }

    public function update(UpdateConsultancyConsultantRequest $request)
    {
        $this->requireSuperAdmin($request);

        $newUserId = $request->validated()['user_id'] ?? null;
        $setting = SuresignSetting::instance();
        $previousUserId = $setting->consultancy_consultant_user_id;

        $setting->update(['consultancy_consultant_user_id' => $newUserId]);

        ActivityLog::record(
            'consultancy.consultant_changed',
            'Consultancy consultant configuration changed.',
            $request->user(),
            $setting,
            ['previous_user_id' => $previousUserId, 'new_user_id' => $newUserId],
        );

        return response()->json([
            'consultant' => $this->consultantResolver->resolve() ? [
                'id'    => $this->consultantResolver->resolve()->id,
                'name'  => $this->consultantResolver->resolve()->name,
                'email' => $this->consultantResolver->resolve()->email,
            ] : null,
        ]);
    }

    /**
     * The "new booking" in-app notification recipient policy — read:
     * Super Admin or Admin (matching this controller's own convention);
     * write: Super Admin only. See ConsultancyNewBookingNotificationRecipients'
     * own docblock for the two supported values and the fail-safe default.
     */
    public function notificationSettings(Request $request)
    {
        return response()->json([
            'recipients' => ConsultancyNewBookingNotificationRecipients::current(),
        ]);
    }

    public function updateNotificationSettings(UpdateConsultancyNotificationSettingsRequest $request)
    {
        $this->requireSuperAdmin($request);

        $setting = SuresignSetting::instance();
        $previous = ConsultancyNewBookingNotificationRecipients::current();
        $new = $request->validated()['recipients'];

        $setting->update(['consultancy_new_booking_notification_recipients' => $new]);

        ActivityLog::record(
            'consultancy.notification_settings_changed',
            'Consultancy new-booking notification recipients changed.',
            $request->user(),
            $setting,
            ['previous' => $previous, 'new' => $new],
        );

        return response()->json(['recipients' => $new]);
    }

    /**
     * Eligible candidates for the consultant picker — Admin/Super Admin
     * users only, matching AppointmentAvailabilityService::isEligibleStaff()'s
     * own rule exactly (re-derived here via the query rather than filtering
     * in PHP, since this is a simple list endpoint, not a re-implementation
     * of the eligibility rule itself — the authoritative check still lives
     * in UpdateConsultancyConsultantRequest/ConsultancyConsultantResolver).
     */
    public function eligibleCandidates(Request $request)
    {
        $this->requireSuperAdmin($request);

        $candidates = User::query()
            ->where('is_active', true)
            ->whereNull('banned_at')
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['Admin', 'Super Admin']))
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return response()->json($candidates);
    }

    /**
     * Consultancy Live Booking Activation Hardening — `checkout_blocked`
     * is the operator-visible counterpart to the customer-safe
     * checkoutAvailability() decision the checkout endpoints now enforce:
     * an operator sees the same live blocking state PLUS the full
     * per-check breakdown below it, so "why is checkout blocked" never
     * requires cross-referencing two endpoints.
     */
    public function readiness(Request $request)
    {
        $status = $this->readinessService->check();

        return response()->json([
            ...$status,
            'checkout_blocked' => !$status['ready'],
        ]);
    }

    /**
     * Consultancy Live Booking Upgrade, Stage 2 — minimal Admin
     * diagnostics: counts plus a bounded recent list. Deliberately no
     * customer payment controls (none exist yet) and no attendee email
     * beyond what an operator already sees elsewhere in Consultancy.
     */
    public function reservations(Request $request)
    {
        $counts = ConsultancySlotReservation::query()
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $recent = ConsultancySlotReservation::query()
            ->with('consultancyService:id,code,display_name')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (ConsultancySlotReservation $r) => [
                'id'           => $r->id,
                'service'      => $r->consultancyService?->display_name,
                'starts_at'    => $r->starts_at->toIso8601String(),
                'ends_at'      => $r->ends_at->toIso8601String(),
                'status'       => $r->status,
                'expires_at'   => $r->expires_at->toIso8601String(),
            ]);

        return response()->json([
            'counts' => [
                'active'    => $counts['active'] ?? 0,
                'consumed'  => $counts['consumed'] ?? 0,
                'expired'   => $counts['expired'] ?? 0,
                'cancelled' => $counts['cancelled'] ?? 0,
            ],
            'recent' => $recent,
        ]);
    }

    public function cancelReservation(Request $request, ConsultancySlotReservation $reservation)
    {
        $reservation = $this->reservationService->cancel($reservation, $request->user());

        return response()->json(['status' => $reservation->status]);
    }

    /**
     * Consultancy Live Booking Upgrade, Stage 3 — recovery visibility for
     * payments that never exist in a customer-safe state: paid awaiting
     * conversion, and anything requiring manual review. No payment
     * amendment/refund action exists here — only visibility and a safe
     * conversion retry.
     */
    public function payments(Request $request)
    {
        $counts = ConsultancyPayment::query()
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $needsAttention = ConsultancyPayment::query()
            ->whereIn('status', ['conversion_pending', 'manual_review'])
            ->with('consultancyService:id,code,display_name')
            ->orderBy('created_at')
            ->limit(50)
            ->get()
            ->map(fn (ConsultancyPayment $p) => [
                'id'         => $p->id,
                'service'    => $p->service_name_snapshot,
                'status'     => $p->status,
                'amount'     => $p->total_minor_units,
                'currency'   => $p->currency,
                'starts_at'  => $p->starts_at_snapshot->toIso8601String(),
                'paid_at'    => $p->paid_at?->toIso8601String(),
            ]);

        return response()->json([
            'counts' => [
                'checkout_open'      => $counts['checkout_open'] ?? 0,
                'paid'               => $counts['paid'] ?? 0,
                'conversion_pending' => $counts['conversion_pending'] ?? 0,
                'converted'          => $counts['converted'] ?? 0,
                'manual_review'      => $counts['manual_review'] ?? 0,
                'expired'            => $counts['expired'] ?? 0,
                'cancelled'          => $counts['cancelled'] ?? 0,
                'failed'             => $counts['failed'] ?? 0,
            ],
            'needs_attention' => $needsAttention,
        ]);
    }

    /**
     * A safe, explicit retry of local Appointment conversion for a payment
     * already confirmed paid by Stripe — never re-charges, never creates a
     * second Appointment (ConsultancyPaymentConversionService's own
     * idempotency guarantees this).
     */
    public function retryConversion(Request $request, ConsultancyPayment $payment)
    {
        if (!$payment->confirming_stripe_event_id) {
            return response()->json(['message' => 'This payment has no confirming Stripe event to retry conversion from.'], 422);
        }

        try {
            $this->conversionService->convert($payment, $payment->confirming_stripe_event_id);
        } catch (ConsultancyConversionRetryableException $e) {
            return response()->json(['message' => 'Conversion failed again and remains retryable.', 'status' => 'conversion_pending'], 409);
        } catch (ConsultancyManualReviewRequiredException $e) {
            return response()->json(['message' => $e->getMessage(), 'status' => 'manual_review'], 409);
        }

        return response()->json(['status' => $payment->fresh()->status]);
    }
}
