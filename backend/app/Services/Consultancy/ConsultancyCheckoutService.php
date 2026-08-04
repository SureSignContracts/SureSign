<?php

namespace App\Services\Consultancy;

use App\Models\ActivityLog;
use App\Models\ConsultancyPayment;
use App\Models\ConsultancyService;
use App\Models\ConsultancySlotReservation;
use App\Services\Billing\BillingProviderInterface;
use App\Support\Billing\OneOffCheckoutRequest;
use App\Support\Consultancy\ConsultancyTaxTreatment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Consultancy Live Booking Upgrade, Stage 3 — prepares the immutable
 * commercial snapshot and creates the Stripe Checkout Session for an
 * active reservation. The authoritative sequence (approved architecture):
 *
 *   1. Resolve and lock the active reservation.
 *   2. Revalidate ownership and lifecycle state.
 *   3. Revalidate the selected Consultancy service.
 *   4. Read the current authoritative commercial values.
 *   5. Create the immutable consultancy_payments snapshot.
 *   6. Create the Stripe Checkout Session from that snapshot.
 *   7. Persist the Stripe Session identifiers and expiry.
 *   8. Extend the reservation expiry to the Stripe Checkout expiry.
 *   9. Commit.
 *
 * After step 5, every downstream read (Checkout, webhook processing,
 * conversion) uses ONLY the snapshot columns on the returned
 * ConsultancyPayment — never the live ConsultancyService/AppointmentType
 * again. A later edit to the service's price/name/description/duration
 * affects only a FUTURE Checkout for a different reservation.
 *
 * This does NOT acquire AppointmentSchedulingService's shared consultant
 * lock — Checkout creation does not change consultant or occupied time
 * (the reservation already secured both); only the reservation row itself
 * is locked, which is sufficient to serialise duplicate Checkout attempts
 * for the SAME reservation and to make replacement/cancellation safe
 * against a concurrent Checkout-creation attempt.
 *
 * Deliberately TWO separate local transactions, not one spanning the
 * Stripe API call: (a) lock the reservation, revalidate, and commit the
 * 'creating' snapshot row — releasing the lock immediately, since holding
 * a database row lock for the duration of an external HTTP call is unsafe
 * practice (a slow/hanging provider call would otherwise block every other
 * scheduling write for this consultant, via the very consultant-row lock
 * this reservation doesn't even need to take here); then (b), outside any
 * lock, call the provider and persist the outcome in its own short
 * transaction. This is also what makes the failure path actually work: an
 * exception raised INSIDE a DB::transaction() closure rolls back
 * everything in that closure, including a same-transaction "mark this
 * payment failed" update — the failure marker would be silently undone
 * along with the payment row itself if both lived in one transaction.
 */
class ConsultancyCheckoutService
{
    public function __construct(private readonly BillingProviderInterface $provider)
    {
    }

    /**
     * @throws \RuntimeException if the reservation is no longer active, or the service is no longer available
     */
    public function createCheckoutSession(ConsultancySlotReservation $reservation, string $successUrl, string $cancelUrl): ConsultancyPayment
    {
        $prepared = $this->prepareSnapshot($reservation);
        if ($prepared instanceof ConsultancyPayment) {
            return $prepared; // idempotent reuse of an existing open Checkout
        }
        [$payment, $reservationId, $bookingAttemptToken] = $prepared;

        $expiresAt = Carbon::now()->addMinutes((int) config('consultancy.checkout_expiry_minutes', 30));

        try {
            $session = $this->provider->createOneOffCheckoutSession(new OneOffCheckoutRequest(
                amountMinorUnits: $payment->amount_minor_units,
                currency: $payment->currency,
                productName: $payment->service_name_snapshot,
                productDescription: $payment->description_snapshot,
                successUrl: $successUrl,
                cancelUrl: $cancelUrl,
                expiresAt: $expiresAt,
                metadata: [
                    'purpose'                    => 'consultancy',
                    'consultancy_payment_id'     => (string) $payment->id,
                    'consultancy_reservation_id' => (string) $reservationId,
                ],
                idempotencyKey: "consultancy_checkout:{$payment->id}",
            ));
        } catch (\Throwable $e) {
            // Provider call failed — a plain single-row update, no
            // transaction to roll back. The reservation's original expiry
            // is untouched (never extended) since we never reach that
            // step. A fresh call to this method creates a new payment row.
            $payment->update(['status' => 'failed', 'failed_at' => Carbon::now()]);
            throw new \RuntimeException('Unable to start payment for this booking. Please try again.', previous: $e);
        }

        return DB::transaction(function () use ($payment, $reservationId, $bookingAttemptToken, $session, $expiresAt) {
            $payment->update([
                'stripe_checkout_session_id' => $session['id'],
                'checkout_url'               => $session['url'] ?? null,
                'checkout_expires_at'        => $expiresAt,
                'status'                     => 'checkout_open',
            ]);

            // Step 8 — extend the reservation to match, ONLY now that
            // Checkout creation has actually succeeded. Locked fresh here
            // (briefly, no external call inside this transaction) rather
            // than trusting the caller's own in-memory copy.
            ConsultancySlotReservation::where('id', $reservationId)->lockForUpdate()->update(['expires_at' => $expiresAt]);

            ActivityLog::record(
                'consultancy.payment_checkout_created',
                "Consultancy Checkout created for {$payment->service_name_snapshot}.",
                null,
                $payment,
                [
                    'consultancy_service_code'    => $payment->service_code_snapshot,
                    'booking_attempt_token_hash'  => hash('sha256', $bookingAttemptToken),
                ],
            );

            return $payment->fresh();
        });
    }

    /**
     * Steps 1-5 of the approved sequence, in their own short transaction:
     * lock + revalidate the reservation, revalidate the service, and
     * commit the immutable 'creating' snapshot row. Returns the existing
     * ConsultancyPayment directly if idempotent reuse applies, otherwise a
     * tuple of [payment, reservationId, bookingAttemptToken] — the caller
     * needs the token only for the (hashed) Activity Log entry, never the
     * live reservation model past this point.
     *
     * @return ConsultancyPayment|array{0: ConsultancyPayment, 1: int, 2: string}
     * @throws \RuntimeException
     */
    private function prepareSnapshot(ConsultancySlotReservation $reservation): ConsultancyPayment|array
    {
        return DB::transaction(function () use ($reservation) {
            $locked = ConsultancySlotReservation::where('id', $reservation->id)->lockForUpdate()->first();
            if (!$locked || !$locked->isActiveAndUnexpired()) {
                throw new \RuntimeException('This reservation is no longer active. Please choose another time.');
            }

            // Idempotent reuse — one open Checkout per active reservation.
            // A previous Session that hasn't itself expired is returned
            // unchanged rather than creating a second payable Session.
            $existing = ConsultancyPayment::where('reservation_id', $locked->id)
                ->where('status', 'checkout_open')
                ->first();
            if ($existing && $existing->checkout_expires_at?->isFuture()) {
                return $existing;
            }

            $service = ConsultancyService::with('appointmentType')->find($locked->consultancy_service_id);
            if (!$service || !$service->enabled) {
                throw new \RuntimeException('This service is no longer available.');
            }
            $type = $service->appointmentType;

            $amount = $service->price_minor_units ?? 0;
            $currency = strtoupper($service->currency);

            $payment = ConsultancyPayment::create([
                'reservation_id'              => $locked->id,
                'consultancy_service_id'      => $service->id,
                'service_code_snapshot'       => $service->code,
                'service_name_snapshot'       => $service->display_name,
                'description_snapshot'        => $service->public_description,
                'consultant_user_id_snapshot' => $locked->consultant_user_id,
                'duration_minutes_snapshot'   => $type->duration_minutes,
                'starts_at_snapshot'          => $locked->starts_at,
                'ends_at_snapshot'            => $locked->ends_at,
                'booking_timezone_snapshot'   => $locked->booking_timezone,
                'amount_minor_units'          => $amount,
                'currency'                    => $currency,
                'tax_treatment'               => ConsultancyTaxTreatment::NOT_SEPARATELY_CALCULATED,
                'subtotal_minor_units'        => $amount,
                'tax_minor_units'             => 0,
                'total_minor_units'           => $amount,
                'attendee_name_snapshot'      => $locked->attendee_name,
                'attendee_email_snapshot'     => $locked->attendee_email,
                'organization_id'             => $locked->organization_id,
                'linked_user_id'              => $locked->linked_user_id,
                'booking_attempt_token'       => $locked->booking_attempt_token,
                'status'                      => 'creating',
                'provider'                    => 'stripe',
                'livemode'                    => $this->provider->isLivemode(),
            ]);

            return [$payment, $locked->id, $locked->booking_attempt_token];
        });
    }
}
