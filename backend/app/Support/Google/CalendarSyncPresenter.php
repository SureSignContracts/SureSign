<?php

namespace App\Support\Google;

use App\Models\AppointmentExternalSync;

/**
 * Stage 4B.1/4B.2 — the single place an AppointmentExternalSync row is
 * shaped for an Admin diagnostics response. No customer-facing endpoint
 * exists for this data at all in this stage — every method here is
 * Admin-only by definition. `provider_event_id`/`provider_conference_id`
 * are intentionally included (operationally justified, mirrors the
 * existing precedent of Admin-only fields elsewhere in this codebase,
 * e.g. AI Telemetry reporting) — this is NOT a template for any future
 * customer-facing response. `meeting_join_url` is deliberately NEVER
 * included here either — Admin diagnostics answer "does a joining link
 * exist" (`meeting_link_known`), never the link's value itself, matching
 * the same discipline applied to the customer-facing presenter (see
 * App\Support\Consultancy\ConsultationMeetingPresenter).
 */
class CalendarSyncPresenter
{
    /**
     * @return array{
     *     id: int,
     *     appointment_id: int,
     *     appointment_reference: ?string,
     *     appointment_status: ?string,
     *     appointment_cancelled: bool,
     *     state: string,
     *     provider: string,
     *     provider_event_id: ?string,
     *     event_exists: bool,
     *     meeting_state: string,
     *     provider_conference_id: ?string,
     *     provider_conference_type: ?string,
     *     meeting_known: bool,
     *     meeting_link_known: bool,
     *     meeting_failure_category: ?string,
     *     attempt_count: int,
     *     outcome_uncertain: bool,
     *     failure_category: ?string,
     *     failure_message: ?string,
     *     last_attempted_at: ?string,
     *     last_success_at: ?string,
     *     next_retry_at: ?string,
     *     created_at: ?string,
     * }
     */
    public static function admin(AppointmentExternalSync $sync): array
    {
        $appointment = $sync->appointment;

        return [
            'id'                     => $sync->id,
            'appointment_id'         => $sync->appointment_id,
            'appointment_reference'  => $appointment?->reference,
            'appointment_status'     => $appointment?->status,
            // Explicitly independent facts — see approved correction 5:
            // a SYNCED sync row and a cancelled Appointment can both be
            // true at once, and this response must show both rather than
            // let one field imply the other.
            'appointment_cancelled'  => $appointment ? $appointment->status === 'cancelled' : false,
            'state'                  => $sync->state,
            'provider'               => $sync->provider,
            'provider_event_id'      => $sync->provider_event_id,
            'event_exists'           => $sync->provider_event_id !== null,
            'meeting_state'          => $sync->meeting_state,
            'provider_conference_id' => $sync->provider_conference_id,
            'provider_conference_type' => $sync->provider_conference_type,
            'meeting_known'          => $sync->provider_conference_id !== null,
            'meeting_link_known'     => $sync->isMeetingJoinable(),
            'meeting_failure_category' => $sync->meeting_failure_category,
            'attempt_count'          => $sync->attempt_count,
            'outcome_uncertain'      => $sync->outcome_uncertain,
            'failure_category'       => $sync->failure_category,
            'failure_message'        => $sync->failure_message,
            'last_attempted_at'      => $sync->last_attempted_at?->toIso8601String(),
            'last_success_at'        => $sync->last_success_at?->toIso8601String(),
            'next_retry_at'          => $sync->next_retry_at?->toIso8601String(),
            'created_at'             => $sync->created_at?->toIso8601String(),
        ];
    }
}
