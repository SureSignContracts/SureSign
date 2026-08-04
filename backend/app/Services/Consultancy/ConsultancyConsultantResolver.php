<?php

namespace App\Services\Consultancy;

use App\Models\SuresignSetting;
use App\Models\User;
use App\Services\AppointmentAvailabilityService;

/**
 * The single authoritative source of "who is the configured Consultancy
 * consultant" — Consultancy Live Booking Upgrade, Stage 1. Every Consultancy
 * booking/slot-generation call site resolves the consultant through here,
 * exclusively; nothing reads `suresign_settings.consultancy_consultant_user_id`
 * directly, and nothing stores a redundant copy on ConsultancyService/
 * AppointmentType (see the Phase 0 architecture review's "Consultant
 * configuration" section for why a per-service copy would create two
 * competing sources of truth).
 *
 * resolve() fails safe to null — never an arbitrary fallback Admin/Super
 * Admin — whenever no consultant is configured, or the configured user no
 * longer passes AppointmentAvailabilityService::isEligibleStaff() (inactive,
 * banned, soft-deleted, or no longer Admin/Super Admin). A null result must
 * be treated as "Consultancy scheduling is not ready" by every caller,
 * exactly like an ordinary Appointment Type with no fixed staff member
 * today falls back to the manual/free-text booking path — never a crash,
 * never a silent fallback to some other user.
 *
 * Changing the configured consultant only ever affects future resolve()
 * calls — it never touches an already-created Appointment row, which keeps
 * its own assigned_user_id exactly as it was at booking time (see
 * Appointment::$assigned_user_id — the durable per-booking snapshot).
 */
class ConsultancyConsultantResolver
{
    public function __construct(private readonly AppointmentAvailabilityService $availabilityService)
    {
    }

    public function resolve(): ?User
    {
        $userId = SuresignSetting::instance()->consultancy_consultant_user_id;
        if (!$userId) {
            return null;
        }

        $user = User::find($userId);
        if (!$user || !$this->availabilityService->isEligibleStaff($user)) {
            return null;
        }

        return $user;
    }

    public function isConfigured(): bool
    {
        return $this->resolve() !== null;
    }
}
