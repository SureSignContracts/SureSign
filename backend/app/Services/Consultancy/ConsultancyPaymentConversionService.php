<?php

namespace App\Services\Consultancy;

use App\Models\ActivityLog;
use App\Models\Appointment;
use App\Models\ConsultancyPayment;
use App\Models\ConsultancySlotReservation;
use App\Models\ConsultationEnquiry;
use App\Models\User;
use App\Services\AppointmentReferenceService;
use App\Services\AppointmentSchedulingService;
use App\Services\Calendar\AppointmentCalendarSyncService;
use App\Services\Consultancy\Exceptions\ConsultancyConversionRetryableException;
use App\Services\Consultancy\Exceptions\ConsultancyManualReviewRequiredException;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Consultancy Live Booking Upgrade, Stage 3 — the atomic LOCAL conversion
 * boundary. Stripe's payment is already an external, completed fact by the
 * time this runs; this class only atomically handles the local
 * consequences of that fact:
 *
 *   1. acquire the shared consultant scheduling lock (same boundary every
 *      other Appointment/Consultancy write already uses — see
 *      AppointmentSchedulingService::withConflictCheck()'s own docblock);
 *   2. lock the ConsultancyPayment row;
 *   3. lock the ConsultancySlotReservation row;
 *   4. verify this exact conversion hasn't already happened (idempotent);
 *   5. verify the reservation is in a state conversion can safely resolve;
 *   6. create the Appointment exactly once;
 *   7. mark the reservation consumed;
 *   8. mark the payment converted;
 *   9. commit.
 *
 * If local conversion fails for any reason AFTER Stripe has already been
 * paid, this NEVER reports the payment as failed — see
 * ConsultancyConversionRetryableException. The caller
 * (App\Services\Consultancy\ConsultancyWebhookEventProcessor) moves the
 * payment to 'conversion_pending' and leaves it retryable, never 'failed'.
 *
 * Per the approved expiry-race correction: this method does NOT reject a
 * reservation merely because `expires_at <= now()` — Stripe having
 * reported `status: complete`/`payment_status: paid` is, by construction,
 * proof payment completed before the aligned Checkout/reservation expiry
 * (Stripe does not allow a Session to complete after its own expiry). Only
 * an INDEPENDENTLY cancelled/inconsistent reservation blocks conversion —
 * see handleCancelledReservation() below.
 */
class ConsultancyPaymentConversionService
{
    public function __construct(
        private readonly AppointmentSchedulingService $schedulingService,
        private readonly AppointmentReferenceService $referenceService,
        private readonly AppointmentCalendarSyncService $calendarSyncService,
    ) {
    }

    /**
     * @throws ConsultancyConversionRetryableException
     * @throws ConsultancyManualReviewRequiredException
     */
    public function convert(ConsultancyPayment $payment, string $confirmingStripeEventId): void
    {
        try {
            DB::transaction(function () use ($payment, $confirmingStripeEventId) {
                $consultant = User::find($payment->consultant_user_id_snapshot);
                if (!$consultant) {
                    throw new ConsultancyManualReviewRequiredException("Consultant snapshot user {$payment->consultant_user_id_snapshot} no longer exists.");
                }

                // Step 1 — the same stable, always-present row every other
                // scheduling write path locks first (see
                // AppointmentSchedulingService's own docblock).
                User::where('id', $consultant->id)->lockForUpdate()->first();

                // Step 2.
                $lockedPayment = ConsultancyPayment::where('id', $payment->id)->lockForUpdate()->first();

                if ($lockedPayment->status === 'converted') {
                    return; // Already converted by a previous run — idempotent no-op.
                }
                if (!in_array($lockedPayment->status, ['paid', 'conversion_pending'], true)) {
                    throw new ConsultancyManualReviewRequiredException("Cannot convert a payment in status \"{$lockedPayment->status}\".");
                }

                // Step 3.
                $reservation = ConsultancySlotReservation::where('id', $lockedPayment->reservation_id)->lockForUpdate()->first();
                if (!$reservation) {
                    throw new ConsultancyManualReviewRequiredException('Reservation missing for a paid Consultancy payment.');
                }

                if ($reservation->status === 'consumed') {
                    if ($lockedPayment->appointment_id && Appointment::where('id', $lockedPayment->appointment_id)->exists()) {
                        // The SAME payment already converted this exact
                        // reservation — finish marking this payment
                        // converted (a retry that got this far before).
                        $lockedPayment->update(['status' => 'converted', 'converted_at' => Carbon::now(), 'confirming_stripe_event_id' => $confirmingStripeEventId]);

                        // A prior interrupted run may have committed the
                        // Appointment but crashed before dispatching Stage
                        // 4B.1's Calendar sync — ensure it's queued now.
                        // AppointmentCalendarSyncService::queueForAppointment()
                        // is itself idempotent (one sync row per
                        // Appointment), so this is always safe even if
                        // sync was already queued/completed.
                        $resumedAppointmentId = $lockedPayment->appointment_id;
                        DB::afterCommit(function () use ($resumedAppointmentId) {
                            $appointment = Appointment::find($resumedAppointmentId);
                            if ($appointment) {
                                $this->calendarSyncService->queueForAppointment($appointment);
                            }
                        });

                        return;
                    }
                    // Consumed by something else entirely — a genuine
                    // mismatch, never guessed through.
                    throw new ConsultancyManualReviewRequiredException('Reservation was already consumed by a different payment.');
                }

                if ($reservation->status === 'cancelled') {
                    $this->handleCancelledReservation($lockedPayment, $reservation, $consultant);
                    // handleCancelledReservation() either returns having
                    // safely proceeded (time still free) or throws — if we
                    // reach here, the time is confirmed still allocatable.
                }

                if (!in_array($reservation->status, ['active', 'expired', 'cancelled'], true)) {
                    throw new ConsultancyManualReviewRequiredException("Reservation is in an unexpected status \"{$reservation->status}\".");
                }

                // Step 6 — create the Appointment from SNAPSHOT data only,
                // never re-reading the live ConsultancyService for any
                // commercial/scheduling value — the linked type is looked
                // up only for its immutable ID, needed for the FK.
                $appointmentTypeId = \App\Models\ConsultancyService::where('id', $lockedPayment->consultancy_service_id)->value('appointment_type_id');
                if (!$appointmentTypeId) {
                    throw new ConsultancyManualReviewRequiredException('Cannot resolve the Appointment Type for this Consultancy service.');
                }

                $reference = $this->referenceService->generate();
                $appointment = Appointment::create([
                    'reference'           => $reference,
                    'appointment_type_id' => $appointmentTypeId,
                    'assigned_user_id'    => $lockedPayment->consultant_user_id_snapshot,
                    'organization_id'     => $lockedPayment->organization_id,
                    'linked_user_id'      => $lockedPayment->linked_user_id,
                    'attendee_name'       => $lockedPayment->attendee_name_snapshot,
                    'attendee_email'      => $lockedPayment->attendee_email_snapshot,
                    'attendee_timezone'   => $lockedPayment->booking_timezone_snapshot,
                    'starts_at'           => $lockedPayment->starts_at_snapshot,
                    'ends_at'             => $lockedPayment->ends_at_snapshot,
                    'booking_timezone'    => $lockedPayment->booking_timezone_snapshot,
                    'status'              => 'confirmed',
                    'booking_source'      => $lockedPayment->linked_user_id ? 'consultancy_in_app' : 'public_booking_page',
                ]);

                // Stage 3 does not yet collect free-text enquiry details at
                // reservation time (see ConsultancySlotReservation's own
                // scope boundary) — a generated title/description is used
                // here rather than inventing a new customer-facing form
                // field outside this stage's approved scope.
                ConsultationEnquiry::create([
                    'appointment_id'         => $appointment->id,
                    'consultancy_service_id' => $lockedPayment->consultancy_service_id,
                    'title'                  => $lockedPayment->service_name_snapshot,
                    'description'            => 'Consultation booked and paid via Consultancy live booking.',
                    'submitted_by'           => $lockedPayment->linked_user_id ? 'authenticated' : 'public',
                ]);

                // Steps 7-8.
                $reservation->update(['status' => 'consumed', 'consumed_at' => Carbon::now()]);
                $lockedPayment->update([
                    'status'                     => 'converted',
                    'converted_at'               => Carbon::now(),
                    'appointment_id'             => $appointment->id,
                    'confirming_stripe_event_id' => $confirmingStripeEventId,
                ]);

                ActivityLog::record(
                    'consultancy.payment_converted',
                    "Consultancy payment converted to confirmed appointment {$appointment->reference}.",
                    null,
                    $lockedPayment,
                    ['appointment_id' => $appointment->id, 'reservation_id' => $reservation->id],
                    null,
                    $lockedPayment->organization_id,
                );

                // Stage 4B.1 — Google Calendar synchronisation is queued
                // only after this ENTIRE transaction commits (payment
                // conversion, reservation consumption, and the Appointment
                // itself are all durable facts by the time this runs; no
                // consultant lock is held — locks release at commit).
                // Google failure must never roll back any of the above,
                // which is exactly why this is a queued job dispatched
                // after commit, never an inline call here.
                DB::afterCommit(function () use ($appointment) {
                    $this->calendarSyncService->queueForAppointment($appointment);
                });
            });
        } catch (ConsultancyManualReviewRequiredException $e) {
            $payment->update(['status' => 'manual_review']);
            ActivityLog::record(
                'consultancy.payment_manual_review_required',
                "Consultancy payment requires manual review: {$e->getMessage()}",
                null,
                $payment,
                [],
            );
            throw $e;
        } catch (\Throwable $e) {
            // Any other failure (DB error, unexpected exception) after
            // Stripe has already been paid — NEVER report as a failed
            // payment. Retryable, per the approved distributed-transaction
            // boundary.
            throw new ConsultancyConversionRetryableException('Local Appointment conversion failed and will be retried.', previous: $e);
        }
    }

    /**
     * A paid Checkout whose reservation was independently cancelled (e.g.
     * an operator cancelled it, or it was replaced) is NEVER silently
     * discarded and an Appointment is NEVER created blind. The time must
     * first be confirmed still safely allocatable via the exact same
     * conflict rules every other booking path uses — if it is not, this
     * throws for manual review rather than guessing.
     *
     * @throws ConsultancyManualReviewRequiredException
     */
    private function handleCancelledReservation(ConsultancyPayment $payment, ConsultancySlotReservation $reservation, User $consultant): void
    {
        $type = \App\Models\ConsultancyService::find($payment->consultancy_service_id)?->appointmentType;
        if (!$type) {
            throw new ConsultancyManualReviewRequiredException('Cannot re-verify availability — the linked service/type no longer exists.');
        }

        $stillFree = $this->schedulingService->isSlotFree($consultant->id, $payment->starts_at_snapshot, $payment->ends_at_snapshot)
            && !$this->schedulingService->hasBufferedConflict($consultant->id, $type, $payment->starts_at_snapshot, $payment->ends_at_snapshot);

        if (!$stillFree) {
            throw new ConsultancyManualReviewRequiredException('Reservation was cancelled and the paid time is no longer free — requires manual review.');
        }

        // Time remains free — safe to proceed with conversion despite the
        // reservation itself having been cancelled in the interim.
    }
}
