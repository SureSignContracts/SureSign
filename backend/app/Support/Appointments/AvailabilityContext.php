<?php

namespace App\Support\Appointments;

/**
 * The availability "context" dimension — Consultancy Live Booking Upgrade,
 * Stage 1. `appointment_availabilities`/`appointment_availability_overrides`
 * rows are scoped to exactly one context each, so the same consultant can
 * have an independent weekly schedule/override set for ordinary Appointments
 * (including Book a Demo) and for Consultancy, without a second scheduling
 * engine. `appointment_blocked_periods` deliberately has NO context — a
 * blocked period represents real consultant unavailability and applies to
 * every context (see internal-docs/commercial/consultancy-live-booking-phase-0-architecture-review.md §9).
 *
 * Every AppointmentAvailabilityService/AppointmentSchedulingService method
 * that touches weekly schedule/override rows requires an explicit context —
 * there is no implicit default an authoritative call site relies on. An
 * unrecognised context string must never be silently treated as Consultancy
 * (or as anything else) — callers should validate with isValid() and reject
 * before calling into the scheduling services.
 */
final class AvailabilityContext
{
    public const APPOINTMENTS = 'appointments';
    public const CONSULTANCY = 'consultancy';

    public const ALL = [self::APPOINTMENTS, self::CONSULTANCY];

    public static function isValid(string $context): bool
    {
        return in_array($context, self::ALL, true);
    }
}
