<?php

namespace App\Services\Consultancy;

use App\Models\ActivityLog;
use App\Models\ConsultancyService;
use App\Models\ConsultancySlotReservation;
use App\Models\Organization;
use App\Models\User;
use App\Services\AppointmentSchedulingService;
use App\Support\Appointments\AvailabilityContext;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Consultancy Live Booking Upgrade, Stage 2 — the sole authority for
 * ConsultancySlotReservation's lifecycle (create/replace/cancel/expire).
 * Controllers never transition a reservation directly.
 *
 * Every mutating method delegates its conflict/availability re-check and
 * concurrency protection entirely to the existing, shared
 * AppointmentSchedulingService::withConflictCheck() — the same boundary
 * every Appointment/Consultancy booking path already uses, now also
 * acquiring a `FOR UPDATE` lock on the consultant's own `users` row first
 * (see that class's docblock). No second scheduling engine, no
 * Consultancy-controller-level filtering.
 *
 * Explicitly does NOT: create Stripe Checkout Sessions, store payment
 * state, convert to a confirmed Appointment, or create a Google Calendar
 * event — all deferred to later, separately approved stages.
 */
class ConsultancySlotReservationService
{
    public function __construct(
        private readonly AppointmentSchedulingService $schedulingService,
        private readonly ConsultancyConsultantResolver $consultantResolver,
    ) {
    }

    /**
     * Creates a new active reservation, or — if $bookingAttemptToken
     * already has a still-active, unexpired reservation — returns that
     * existing row unchanged (idempotent retry, never a duplicate).
     *
     * @throws \RuntimeException if Consultancy scheduling isn't ready, or the slot is no longer available
     */
    public function reserve(
        ConsultancyService $service,
        Carbon $startsAt,
        Carbon $endsAt,
        array $attendee,
        string $bookingAttemptToken,
        ?int $organizationId = null,
        ?int $linkedUserId = null,
        ?Organization $organization = null,
    ): ConsultancySlotReservation {
        $existing = $this->findActiveByAttemptToken($bookingAttemptToken);
        if ($existing) {
            return $existing;
        }

        $consultant = $this->consultantResolver->resolve();
        if (!$consultant) {
            throw new \RuntimeException('Consultancy scheduling is not currently available.');
        }

        $type = $service->appointmentType;
        $holdMinutes = (int) config('consultancy.reservation_hold_minutes', 15);

        $create = function () use ($service, $consultant, $startsAt, $endsAt, $attendee, $bookingAttemptToken, $organizationId, $linkedUserId, $holdMinutes) {
            try {
                $reservation = ConsultancySlotReservation::create([
                    'booking_attempt_token'   => $bookingAttemptToken,
                    'active_attempt_token'    => $bookingAttemptToken,
                    'consultancy_service_id'  => $service->id,
                    'consultant_user_id'      => $consultant->id,
                    'organization_id'         => $organizationId,
                    'linked_user_id'          => $linkedUserId,
                    'attendee_name'           => $attendee['name'],
                    'attendee_email'          => $attendee['email'],
                    'starts_at'               => $startsAt,
                    'ends_at'                 => $endsAt,
                    'booking_timezone'        => $attendee['timezone'],
                    'status'                  => 'active',
                    'expires_at'              => Carbon::now()->addMinutes($holdMinutes),
                ]);
            } catch (UniqueConstraintViolationException) {
                // A genuine duplicate-submit race on the SAME attempt token
                // (see active_attempt_token's unique index) — the other
                // request won; return its row rather than erroring.
                return $this->findActiveByAttemptToken($bookingAttemptToken)
                    ?? throw new \RuntimeException('This booking attempt is already in progress.');
            }

            ActivityLog::record(
                'consultancy.reservation_created',
                "Consultancy slot reservation created for {$service->display_name}.",
                null,
                $reservation,
                ['consultancy_service_code' => $service->code],
            );

            return $reservation;
        };

        return $this->schedulingService->withConflictCheck(
            $consultant, $type, $startsAt, $endsAt, null, false, $create, $organization, AvailabilityContext::CONSULTANCY,
        );
    }

    /**
     * Cancels the booking attempt's current active reservation (if any)
     * and creates a new one for the replacement slot — atomically, inside
     * the same locked transaction. The original reservation is preserved
     * as a cancelled audit record, never deleted; it is cancelled only
     * once the replacement slot has been fully validated and secured
     * (i.e. inside the same callback that creates the new row), so the
     * old slot is never released before the new one is confirmed.
     *
     * @throws \RuntimeException if Consultancy scheduling isn't ready, or the replacement slot is unavailable
     */
    public function replace(
        ConsultancyService $service,
        Carbon $startsAt,
        Carbon $endsAt,
        array $attendee,
        string $bookingAttemptToken,
        ?int $organizationId = null,
        ?int $linkedUserId = null,
        ?Organization $organization = null,
    ): ConsultancySlotReservation {
        $consultant = $this->consultantResolver->resolve();
        if (!$consultant) {
            throw new \RuntimeException('Consultancy scheduling is not currently available.');
        }

        $type = $service->appointmentType;
        $holdMinutes = (int) config('consultancy.reservation_hold_minutes', 15);

        $replace = function () use ($service, $consultant, $startsAt, $endsAt, $attendee, $bookingAttemptToken, $organizationId, $linkedUserId, $holdMinutes) {
            $previous = $this->findActiveByAttemptToken($bookingAttemptToken);

            // Cancel first — frees active_attempt_token, so the new insert
            // below (same token) never collides with it, and the previous
            // slot no longer blocks the re-check that already happened
            // inside withConflictCheck() for the NEW slot. Both happen in
            // the one locked transaction withConflictCheck() opened, so no
            // other request can observe an in-between state.
            if ($previous) {
                $previous->update(['status' => 'cancelled', 'cancelled_at' => Carbon::now(), 'active_attempt_token' => null]);
            }

            try {
                $reservation = ConsultancySlotReservation::create([
                    'booking_attempt_token'   => $bookingAttemptToken,
                    'active_attempt_token'    => $bookingAttemptToken,
                    'consultancy_service_id'  => $service->id,
                    'consultant_user_id'      => $consultant->id,
                    'organization_id'         => $organizationId,
                    'linked_user_id'          => $linkedUserId,
                    'attendee_name'           => $attendee['name'],
                    'attendee_email'          => $attendee['email'],
                    'starts_at'               => $startsAt,
                    'ends_at'                 => $endsAt,
                    'booking_timezone'        => $attendee['timezone'],
                    'status'                  => 'active',
                    'expires_at'              => Carbon::now()->addMinutes($holdMinutes),
                ]);
            } catch (UniqueConstraintViolationException) {
                return $this->findActiveByAttemptToken($bookingAttemptToken)
                    ?? throw new \RuntimeException('This booking attempt is already in progress.');
            }

            ActivityLog::record(
                'consultancy.reservation_replaced',
                "Consultancy slot reservation replaced for {$service->display_name}.",
                null,
                $reservation,
                ['previous_reservation_id' => $previous?->id, 'consultancy_service_code' => $service->code],
            );

            return $reservation;
        };

        return $this->schedulingService->withConflictCheck(
            $consultant, $type, $startsAt, $endsAt, null, false, $replace, $organization, AvailabilityContext::CONSULTANCY,
        );
    }

    /**
     * Idempotent — cancelling an already-terminal reservation is a safe
     * no-op, never an error (mirrors AppointmentWorkflowService's own
     * "already in that status" discipline being the ONE case this method
     * treats specially, rather than throwing).
     */
    public function cancel(ConsultancySlotReservation $reservation, ?User $actor = null): ConsultancySlotReservation
    {
        if ($reservation->status !== 'active') {
            return $reservation;
        }

        $reservation->update(['status' => 'cancelled', 'cancelled_at' => Carbon::now(), 'active_attempt_token' => null]);

        ActivityLog::record(
            'consultancy.reservation_cancelled',
            'Consultancy slot reservation cancelled.',
            $actor,
            $reservation,
            [],
        );

        return $reservation->refresh();
    }

    /**
     * Marks every elapsed 'active' reservation 'expired' — called by the
     * `consultancy:reservations:expire` scheduled command. Idempotent
     * (only ever touches rows still 'active') and safe to run
     * concurrently/repeatedly; does not itself need the consultant-row
     * lock, since it never creates a new blocking interval, only clears
     * one that has already stopped blocking (per isSlotFree()'s own
     * expires_at check) — a plain bulk UPDATE is sufficient here.
     */
    public function expireDue(): int
    {
        return ConsultancySlotReservation::query()
            ->where('status', 'active')
            ->where('expires_at', '<=', Carbon::now())
            ->update(['status' => 'expired', 'active_attempt_token' => null]);
    }

    public function findActiveByAttemptToken(string $bookingAttemptToken): ?ConsultancySlotReservation
    {
        return ConsultancySlotReservation::where('active_attempt_token', $bookingAttemptToken)
            ->where('status', 'active')
            ->where('expires_at', '>', Carbon::now())
            ->first();
    }

    public function findByPublicToken(string $publicToken): ?ConsultancySlotReservation
    {
        return ConsultancySlotReservation::where('public_token', $publicToken)->first();
    }
}
