<?php

namespace App\Support\Consultancy;

use App\Models\ActivityLog;
use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\ConsultancyService;
use App\Models\Client;
use App\Models\ConsultationEnquiry;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;

/**
 * Explicit, hand-whitelisted array shaping for every Consultancy response
 * that reaches an authenticated customer — mirroring the exact discipline
 * `App\Support\AI\AiAnalysisPresenter`/`App\Support\Billing\BillingPresenter`
 * already established. This codebase has no app/Http/Resources layer —
 * this stays a plain class of static methods for the same reason.
 *
 * Phase C2, Batch 3 added `operator()` — the consultant/platform-operator
 * shape, including a computed `permissions` block. Its own whitelist is
 * deliberately separate from (and, in several relations, wider than)
 * `customerFacing()`'s: an operator legitimately needs full attendee
 * contact detail, the assigned consultant's id (not just name), the
 * service code, and every Consultancy-internal field. Only fields
 * genuinely required for the read-only Batch 3 workspace are included —
 * a field with no current reader (e.g. `customer_summary_published_by`,
 * meaningless while nothing can publish yet) is added in the batch that
 * first needs it (Batch 4), not speculatively now. See
 * internal-docs/commercial/suresign-consultancy-phase-c2-specification-v1.md
 * §8/§16, Batch 3.
 *
 * Deterministic contract for the customer summary: `customer_summary_published`
 * and `customer_summary_published_at` are ALWAYS present keys, `null` until
 * a real publish happens (Batch 4) — never a conditionally-omitted key.
 * This matches the existing convention `BillingOverviewService::presentSubscription()`
 * already established for `pending_checkout` ("exposes ... null otherwise"),
 * and produces a simpler, more honest frontend type (`string | null`) than
 * an optional key would.
 *
 * Every nested relation is shaped through its own small, explicit helper
 * below — never a raw `$model->toArray()` passthrough. This is what stops a
 * future column added to `ConsultancyService`/`User`/`AppointmentType`/
 * `ConsultationEnquiry` from silently leaking through a loaded relationship
 * even though the top-level whitelist itself was never touched.
 */
class ConsultationPresenter
{
    public static function customerFacing(Appointment $appointment): array
    {
        return [
            'id'                   => $appointment->id,
            'reference'            => $appointment->reference,
            'status'               => $appointment->status,
            'starts_at'            => $appointment->starts_at,
            'ends_at'              => $appointment->ends_at,
            'booking_timezone'     => $appointment->booking_timezone,
            'attendee_name'        => $appointment->attendee_name,
            'attendee_email'       => $appointment->attendee_email,
            'appointment_type'     => self::appointmentTypeRef($appointment->appointmentType),
            'assigned_user'        => self::assignedConsultantRef($appointment->assignedUser),
            'consultation_enquiry' => self::enquiryRef($appointment->consultationEnquiry),
            // Deliberately omitted, regardless of sensitivity level:
            // internal_notes, engagement_status, customer_summary_draft,
            // customer_summary_needs_republish, customer_summary_published_by
            // (all Consultancy operational/internal fields — see
            // ConsultationEnquiry helper below); organization_id,
            // linked_user_id, project_id, created_by_user_id (internal
            // identifiers/assignment metadata); attendee_phone,
            // attendee_job_title, attendee_company, attendee_message
            // (collected but not currently part of the approved customer
            // response — adding them is a deliberate future decision, not an
            // oversight); meeting_url, location, cancellation_reason,
            // completion_notes, metadata (operational/administrative);
            // public_token, schedule_version (signed-link/ICS internals).
        ];
    }

    /**
     * Batch 3 (Consultancy Communications & Global Email Experience
     * Upgrade) — the public, no-account "view your consultation" page and
     * its published-summary counterpart. Deliberately a THIRD, separate
     * whitelist from customerFacing() above, not a reuse of it: the
     * authenticated shape includes the Appointment's own numeric `id`
     * (needed to build the in-app `/app/consultations/{id}` route) — a
     * public, unauthenticated visitor is identified only by the opaque
     * `public_token` already in their URL, so the numeric id must never
     * appear here at all, not merely be unused by the frontend. Also
     * omits attendee_email (already known to the visitor, but pointless
     * exposure surface) and the enquiry's free-text fields
     * (title/description/project_stage/contract_form/preferred_outcome) —
     * those were only ever meant for the consultant, not restated back to
     * the customer on a page whose entire job is status/scheduling/summary
     * access.
     */
    public static function publicView(Appointment $appointment): array
    {
        $enquiry = $appointment->consultationEnquiry;

        return [
            'reference'                     => $appointment->reference,
            'status'                        => $appointment->status,
            'starts_at'                     => $appointment->starts_at,
            'ends_at'                       => $appointment->ends_at,
            'booking_timezone'              => $appointment->booking_timezone,
            'attendee_name'                 => $appointment->attendee_name,
            'consultancy_service'           => self::consultancyServiceRef($enquiry?->consultancyService),
            'assigned_consultant'           => self::assignedConsultantRef($appointment->assignedUser),
            'customer_summary_published'    => $enquiry?->customer_summary_published !== null,
            'customer_summary_published_at' => $enquiry?->customer_summary_published_at,
        ];
    }

    /**
     * The public, no-account published-summary page (Batch 3, Scope E).
     * Only ever called by a controller that has already verified
     * `customer_summary_published` is non-null — this method itself does
     * not gate on that, matching the existing convention that gating is
     * the controller's job (see PublicConsultationViewController::summary()).
     * `title` is the customer's own submitted enquiry title — already
     * part of the approved authenticated customer response
     * (customerFacing()'s own enquiryRef() includes it), so restating it
     * here for context is not a new disclosure.
     */
    public static function publicSummary(Appointment $appointment): array
    {
        $enquiry = $appointment->consultationEnquiry;

        return [
            'reference'           => $appointment->reference,
            'title'               => $enquiry?->title,
            'consultancy_service' => self::consultancyServiceRef($enquiry?->consultancyService),
            'assigned_consultant' => self::assignedConsultantRef($appointment->assignedUser),
            'starts_at'           => $appointment->starts_at,
            'booking_timezone'    => $appointment->booking_timezone,
            'summary'             => $enquiry?->customer_summary_published,
            'published_at'        => $enquiry?->customer_summary_published_at,
        ];
    }

    private static function appointmentTypeRef(?AppointmentType $type): ?array
    {
        if (!$type) {
            return null;
        }

        return [
            'name' => $type->name,
        ];
    }

    /**
     * Name only — never id, email, or any other identifier/assignment
     * metadata. This is the one piece of consultant identity already part
     * of the approved C1 customer experience (shown on the existing
     * `/app/consultations/{id}` page); nothing new is added here.
     */
    private static function assignedConsultantRef(?User $user): ?array
    {
        if (!$user) {
            return null;
        }

        return [
            'name' => $user->name,
        ];
    }

    private static function consultancyServiceRef(?ConsultancyService $service): ?array
    {
        if (!$service) {
            return null;
        }

        return [
            'display_name' => $service->display_name,
        ];
    }

    private static function enquiryRef(?ConsultationEnquiry $enquiry): ?array
    {
        if (!$enquiry) {
            return null;
        }

        return [
            'title'                         => $enquiry->title,
            'description'                   => $enquiry->description,
            'project_stage'                 => $enquiry->project_stage,
            'contract_form'                 => $enquiry->contract_form,
            'preferred_outcome'             => $enquiry->preferred_outcome,
            'consultancy_service'           => self::consultancyServiceRef($enquiry->consultancyService),
            // Published-only, per the summary publishing rules: an edited
            // draft after publication never changes what the customer sees
            // here — only an explicit (Batch 4) publish/republish action
            // updates these two fields. Always present, null until then.
            'customer_summary_published'    => $enquiry->customer_summary_published,
            'customer_summary_published_at' => $enquiry->customer_summary_published_at,
            // Deliberately omitted: engagement_status, internal_notes,
            // customer_summary_draft, customer_summary_needs_republish,
            // customer_summary_published_by, submitted_by, consultancy_service_id.
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Operator (Phase C2, Batch 3) — Super Admin, assigned Admin, and
    // unassigned Admin all receive this IDENTICAL field set (§7's confirmed
    // visibility model); only the `permissions` block differs. There is no
    // separate "read-only operator" data shape — see this class's own
    // docblock in customerFacing() for why building one would risk drift.
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param bool $canEdit Computed by the calling controller from
     *   authorizeOperatorManage() — never re-derived here. See this
     *   method's own `permissions` block below.
     * @param bool $isSuperAdmin Computed by the calling controller —
     *   gates the two actions (reassign, reopen) that remain Super-Admin-only
     *   even for the assigned Admin.
     * @param bool $includeActivity `show()` (one record) includes the
     *   activity timeline; `index()` (the queue, up to a page of rows)
     *   passes `false` — an ActivityLog query per row would otherwise be a
     *   genuine per-row N+1 for a list nobody asked to see full history in
     *   (Activity is a Detail-only requirement — see this batch's own
     *   Queue vs. Read-only Operator Detail sections). The field list
     *   itself never changes between the two calls, only whether this one
     *   expensive nested collection is computed — deliberately a parameter
     *   on one method, not a second, drift-prone whitelist.
     *
     * Never includes Meet status — a prior version of this docblock said
     * "meeting_url, location (unpopulated until C4)", which went stale
     * once Google Meet actually shipped in Stage 4B without this line
     * being revisited, leaving the operator queue detail page with no way
     * to see or join an available Meet even after it existed everywhere
     * else (customer page, customer/Consultancy emails). Fixed by having
     * `ConsultancyOperationsController::show()` append
     * `ConsultationMeetingPresenter::customerFacing($appointment)` under a
     * `meeting` key alongside this method's own output, reusing the exact
     * same customer-safe, four-state presenter rather than adding a
     * second Meet shape here — its output is equally correct for an
     * operator (richer provider diagnostics remain `CalendarSyncPresenter::admin()`'s
     * job, on the dedicated Google admin diagnostics page, not this one).
     */
    public static function operator(Appointment $appointment, bool $canEdit, bool $isSuperAdmin, bool $includeActivity = true): array
    {
        return [
            'id'                   => $appointment->id,
            'reference'            => $appointment->reference,
            'status'               => $appointment->status,
            'starts_at'            => $appointment->starts_at,
            'ends_at'              => $appointment->ends_at,
            'booking_timezone'     => $appointment->booking_timezone,
            'created_at'           => $appointment->created_at,
            'updated_at'           => $appointment->updated_at,
            'attendee_name'        => $appointment->attendee_name,
            'attendee_email'       => $appointment->attendee_email,
            'attendee_phone'       => $appointment->attendee_phone,
            'attendee_company'     => $appointment->attendee_company,
            'attendee_job_title'   => $appointment->attendee_job_title,
            // A plain scalar FK (not a nested ref) — needed by the Batch 5
            // project picker to scope its search to this consultation's own
            // organisation client-side. organizationRef() below stays
            // name-only by its own existing design; this is a separate field.
            'organization_id'      => $appointment->organization_id,
            'organization'         => self::organizationRef($appointment->organization),
            'appointment_type'     => self::appointmentTypeRef($appointment->appointmentType),
            'assigned_consultant'  => self::assignedConsultantOperatorRef($appointment->assignedUser),
            'consultation_enquiry' => self::enquiryOperatorRef($appointment->consultationEnquiry),
            'project'              => self::projectRef($appointment->project),
            'activity'             => $includeActivity ? self::activityRef($appointment) : null,
            'permissions'          => [
                // Batch 3: informative only — no route acts on any of
                // these yet. Computed from the exact same
                // authorizeOperatorManage() decision Batch 4's write
                // endpoints will themselves re-check independently; this
                // block is a frontend rendering convenience, never itself
                // a security boundary.
                'can_edit_notes'      => $canEdit,
                'can_publish_summary' => $canEdit,
                'can_change_status'   => $canEdit,
                'can_link_project'    => $canEdit,
                'can_reassign'        => $isSuperAdmin,
                'can_reopen'          => $isSuperAdmin,
            ],
            // Deliberately omitted (not yet operationally meaningful —
            // added in the batch that first needs them, not speculatively
            // now): customer_summary_published_by (nothing can publish
            // yet), created_by_user_id, public_token, schedule_version,
            // metadata.
        ];
    }

    private static function organizationRef(?Organization $organization): ?array
    {
        if (!$organization) {
            return null;
        }

        return [
            'name' => $organization->name,
        ];
    }

    /**
     * Operator variant — id included (unlike the customer-facing
     * name-only ref) since the operator workspace needs it to know
     * "is this consultation assigned to me" and, from Batch 4 onward,
     * to drive reassignment. Still never email or any other identifier.
     */
    private static function assignedConsultantOperatorRef(?User $user): ?array
    {
        if (!$user) {
            return null;
        }

        return [
            'id'   => $user->id,
            'name' => $user->name,
        ];
    }

    private static function consultancyServiceOperatorRef(?ConsultancyService $service): ?array
    {
        if (!$service) {
            return null;
        }

        return [
            'code'         => $service->code,
            'display_name' => $service->display_name,
        ];
    }

    private static function enquiryOperatorRef(?ConsultationEnquiry $enquiry): ?array
    {
        if (!$enquiry) {
            return null;
        }

        return [
            'title'                             => $enquiry->title,
            'description'                       => $enquiry->description,
            'project_stage'                     => $enquiry->project_stage,
            'contract_form'                     => $enquiry->contract_form,
            'preferred_outcome'                 => $enquiry->preferred_outcome,
            'submitted_by'                      => $enquiry->submitted_by,
            'consultancy_service'                => self::consultancyServiceOperatorRef($enquiry->consultancyService),
            'engagement_status'                 => $enquiry->engagement_status,
            'internal_notes'                    => $enquiry->internal_notes,
            'customer_summary_draft'            => $enquiry->customer_summary_draft,
            'customer_summary_published'        => $enquiry->customer_summary_published,
            'customer_summary_published_at'     => $enquiry->customer_summary_published_at,
            'customer_summary_needs_republish'  => $enquiry->customer_summary_needs_republish,
        ];
    }

    /**
     * The existing ActivityLog rows for this appointment, most recent
     * first, capped at 20 — read-only display, no new logging introduced
     * by this method or by Batch 3 at all. Actor name only (never an id
     * or email) since the audience here is "what happened," not "look up
     * this person elsewhere."
     */
    private static function activityRef(Appointment $appointment): array
    {
        return ActivityLog::query()
            ->where('subject_type', Appointment::class)
            ->where('subject_id', $appointment->id)
            ->with('user:id,name')
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (ActivityLog $log) => [
                'action'      => $log->action,
                'description' => $log->description,
                'actor_name'  => $log->user?->name,
                'meta'        => $log->metadata,
                'created_at'  => $log->created_at,
            ])
            ->all();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Project linkage (Phase C2, Batch 5)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Used inside `operator()` — id/name/code/status plus the two
     * disambiguating nested refs (`client`, `organization`), kept
     * deliberately distinct rather than flattened into one generic
     * "customer" field. Never a raw Project model or its other columns
     * (contract value, retention terms, addresses, etc.).
     */
    private static function projectRef(?Project $project): ?array
    {
        if (!$project) {
            return null;
        }

        return [
            'id'           => $project->id,
            'name'         => $project->name,
            'code'         => $project->code,
            'status'       => $project->status,
            'client'       => self::clientRef($project->client),
            'organization' => self::organizationWithIdRef($project->organization),
        ];
    }

    private static function clientRef(?Client $client): ?array
    {
        if (!$client) {
            return null;
        }

        return [
            'id'   => $client->id,
            'name' => $client->name,
        ];
    }

    /**
     * A second organisation ref, distinct from operator()'s own
     * organizationRef() (name-only, for the consultation's own
     * organisation) — this one includes `id`, since the frontend project
     * picker/card needs it to disambiguate and to compare against the
     * consultation's own organisation client-side for display purposes
     * (the actual enforcement is server-side, in linkProject()).
     */
    private static function organizationWithIdRef(?Organization $organization): ?array
    {
        if (!$organization) {
            return null;
        }

        return [
            'id'   => $organization->id,
            'name' => $organization->name,
        ];
    }

    /**
     * The Project-side view (Phase C2, Batch 5) — a deliberately lightweight
     * consultation summary for display inside the Project workspace, NOT
     * the full operator() shape. Categorically excludes internal_notes,
     * summary draft/published content, attendee contact details, and the
     * activity log — none of that belongs inside a Project's own page.
     *
     * @param bool $canView Computed by the calling controller from
     *   authorizeOperatorAccess() (platform-wide read, matching Batch 3 —
     *   NOT the narrower authorizeOperatorManage()). This is presentation
     *   information for the frontend only; GET
     *   /admin/consultancy/projects/{project}/consultations itself already
     *   enforces the real boundary before this method is ever called.
     */
    public static function projectSummary(Appointment $appointment, bool $canView): array
    {
        $enquiry = $appointment->consultationEnquiry;

        return [
            'id'                  => $appointment->id,
            'reference'           => $appointment->reference,
            'consultancy_service' => $enquiry ? self::consultancyServiceOperatorRef($enquiry->consultancyService) : null,
            'engagement_status'   => $enquiry?->engagement_status,
            'appointment_status'  => $appointment->status,
            'assigned_consultant' => self::assignedConsultantOperatorRef($appointment->assignedUser),
            'created_at'          => $appointment->created_at,
            'starts_at'           => $appointment->starts_at,
            'permissions'         => [
                'can_view' => $canView,
            ],
        ];
    }
}
