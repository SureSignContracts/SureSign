<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LinkConsultationProjectRequest;
use App\Http\Requests\MarkAwaitingConsultantRequest;
use App\Http\Requests\MarkAwaitingCustomerRequest;
use App\Http\Requests\MarkConsultationCompletedRequest;
use App\Http\Requests\PublishConsultationSummaryRequest;
use App\Http\Requests\ReopenConsultationRequest;
use App\Http\Requests\UnlinkConsultationProjectRequest;
use App\Http\Requests\UpdateConsultationNotesRequest;
use App\Http\Requests\UpdateConsultationSummaryDraftRequest;
use App\Jobs\SendConsultationCommunicationJob;
use App\Jobs\SendConsultationEmailJob;
use App\Models\ActivityLog;
use App\Models\Appointment;
use App\Models\ConsultationEnquiry;
use App\Models\Project;
use App\Services\Consultancy\EngagementLifecycleService;
use App\Support\Consultancy\ConsultationMeetingPresenter;
use App\Support\Consultancy\ConsultationPresenter;
use Illuminate\Http\Request;

/**
 * The consultant/platform-operator surface — Phase C2, Batch 3. Read-only
 * in this batch: `index()` (queue) and `show()` (operator detail) only. No
 * write action exists on this controller yet (Batch 4).
 *
 * Implements the confirmed, Consultancy-specific Admin visibility rule
 * (internal-docs/commercial/suresign-consultancy-phase-c2-specification-v1.md
 * §7): Super Admin and EVERY Admin — not just the assigned one — can view
 * any consultation platform-wide. This is intentionally broader than
 * `AppointmentController`'s own generic rule (which restricts a generic
 * Admin to appointments assigned to them or unassigned) and exists ONLY
 * here, justified by Consultancy's own operational-continuity need. Zero
 * lines of `AppointmentController` are touched by this phase.
 */
class ConsultancyOperationsController extends Controller
{
    public function __construct(
        private readonly EngagementLifecycleService $engagementLifecycleService,
    ) {
    }

    private const SORTABLE_COLUMNS = [
        'created'          => 'created_at',
        'updated'          => 'updated_at',
        'consultation_date' => 'starts_at',
        'customer'         => 'attendee_name',
        'reference'        => 'reference',
    ];

    /**
     * The relations every operator() response needs eager-loaded — kept in
     * one place so index()/show()/every write action can never drift into
     * inconsistent shapes. Extended in Batch 5 with the linked project and
     * its own two disambiguating nested relations.
     */
    private const OPERATOR_RELATIONS = [
        'appointmentType', 'assignedUser:id,name', 'organization:id,name',
        'consultationEnquiry.consultancyService', 'project.client', 'project.organization',
    ];

    /**
     * Every Super Admin/Admin may READ any consultation, platform-wide —
     * the confirmed rule. The `role:Super Admin|Admin` route middleware
     * already excludes every other caller before this method ever runs,
     * so there is deliberately no further per-row restriction here; this
     * method exists explicitly (rather than being omitted as "nothing to
     * check") so the intentional breadth of this rule is visible in code
     * and independently testable, not an accident of an empty method body.
     */
    private function authorizeOperatorAccess(Request $request): void
    {
        $user = $request->user();
        if (!$user->hasRole('Super Admin') && !$user->hasRole('Admin')) {
            abort(403, 'Access denied.');
        }
    }

    /**
     * Write access (used to compute the presenter's `permissions` block in
     * this batch; will gate every real write endpoint from Batch 4
     * onward): Super Admin, or the specific Admin this consultation is
     * assigned to. An unassigned Admin fails this check even though
     * authorizeOperatorAccess() already let them read the same record.
     */
    private function authorizeOperatorManage(Request $request, Appointment $appointment): bool
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin')) {
            return true;
        }

        return $user->hasRole('Admin') && $appointment->assigned_user_id === $user->id;
    }

    public function index(Request $request)
    {
        $this->authorizeOperatorAccess($request);

        $query = Appointment::query()
            ->whereHas('consultationEnquiry')
            ->with(self::OPERATOR_RELATIONS);

        if ($request->filled('engagement_status')) {
            $engagementStatus = $request->string('engagement_status')->toString();
            $query->whereHas('consultationEnquiry', fn ($q) => $q->where('engagement_status', $engagementStatus));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('assigned_user_id')) {
            $query->where('assigned_user_id', $request->integer('assigned_user_id'));
        }
        if ($request->filled('consultancy_service_id')) {
            $serviceId = $request->integer('consultancy_service_id');
            $query->whereHas('consultationEnquiry', fn ($q) => $q->where('consultancy_service_id', $serviceId));
        }
        // Two Batch 6A dashboard quick-link filters — added directly to
        // this existing endpoint (matching Batch 5's own precedent of
        // extending an existing index() rather than building a second
        // query surface), not new domain data: both are derived from
        // fields/events that already exist.
        if ($request->boolean('unassigned')) {
            $query->whereNull('assigned_user_id');
        }
        if ($request->boolean('overdue_awaiting_customer')) {
            $overdueIds = $this->overdueAwaitingCustomerAppointmentIds();
            $query->whereIn('id', $overdueIds);
        }
        if ($request->filled('search')) {
            $search = trim((string) $request->string('search'));
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhere('attendee_name', 'like', "%{$search}%")
                    ->orWhere('attendee_email', 'like', "%{$search}%")
                    ->orWhereHas('organization', fn ($oq) => $oq->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('consultationEnquiry.consultancyService', fn ($sq) => $sq->where('display_name', 'like', "%{$search}%"));
            });
        }

        // Explicit column whitelist — never pass a raw user-supplied column
        // name to orderBy(). Unrecognised sort_by falls back to the most
        // operationally useful default (soonest consultation first).
        $sortByKey = $request->string('sort_by')->toString();
        $sortColumn = self::SORTABLE_COLUMNS[$sortByKey] ?? 'starts_at';
        $sortDirection = $request->string('sort_dir')->toString() === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sortColumn, $sortDirection);

        $appointments = $query->paginate($request->integer('per_page', 25));

        $isSuperAdmin = $request->user()->hasRole('Super Admin');
        $appointments->getCollection()->transform(function (Appointment $appointment) use ($request, $isSuperAdmin) {
            $canEdit = $this->authorizeOperatorManage($request, $appointment);
            // includeActivity: false — a per-row ActivityLog query for a
            // full page of queue results would be a genuine N+1; Activity
            // is a Detail-only requirement (see show() below).
            return ConsultationPresenter::operator($appointment, $canEdit, $isSuperAdmin, includeActivity: false);
        });

        return response()->json($appointments);
    }

    public function show(Request $request, Appointment $appointment)
    {
        $this->authorizeOperatorAccess($request);

        if (!$appointment->consultationEnquiry) {
            abort(404);
        }

        // 'externalSync' is deliberately not in OPERATOR_RELATIONS (shared
        // with index()'s queue list, which has no use for Meet status) —
        // loaded only here, for the single detail view that actually
        // renders it.
        $appointment->load([...self::OPERATOR_RELATIONS, 'externalSync']);

        $canEdit = $this->authorizeOperatorManage($request, $appointment);

        return response()->json([
            ...ConsultationPresenter::operator($appointment, $canEdit, $request->user()->hasRole('Super Admin')),
            'meeting' => ConsultationMeetingPresenter::customerFacing($appointment),
        ]);
    }

    /**
     * Operator dashboard (Phase C2, Batch 6A) — an operational landing page
     * answering "what needs attention?", never a BI/reporting surface.
     * Read-only, aggregate-only; every count is scoped by the exact same
     * base query index() already uses (whereHas('consultationEnquiry')),
     * gated by the same authorizeOperatorAccess() platform-wide-read rule
     * — a caller can never see a count here for a record they couldn't
     * open in the queue.
     *
     * The `attention` ageing buckets are deliberately derived ONLY from the
     * existing `consultation.engagement_status_changed` ActivityLog trail
     * (the most recent entry per consultation whose recorded `to` is
     * `awaiting_customer`) — never from `updated_at`, which is also
     * touched by unrelated edits (notes, summary draft) and would silently
     * understate age. A consultation currently `awaiting_customer` with no
     * such matching ActivityLog row (older/migrated data predating this
     * event being recorded) is placed in `awaiting_customer_unknown_age`
     * rather than assigned an invented timestamp. This is a genuinely
     * additive read against existing data — no new column was introduced
     * for this batch.
     */
    /**
     * Sidebar badge count — mirrors SupportTicketController::counts()'s
     * shape and purpose exactly: a cheap, dedicated endpoint the sidebar
     * can poll on every page load without pulling in dashboardSummary()'s
     * heavier ActivityLog-based ageing-bucket computation. `awaiting_consultant`
     * is "needs your attention" for an operator, the same semantic as
     * Support's own `waiting_for_support`.
     */
    public function counts(Request $request)
    {
        $this->authorizeOperatorAccess($request);

        $counts = ConsultationEnquiry::query()
            ->whereHas('appointment')
            ->selectRaw('engagement_status, count(*) as total')
            ->groupBy('engagement_status')
            ->pluck('total', 'engagement_status');

        return response()->json(['counts' => [
            'awaiting_consultant' => (int) ($counts['awaiting_consultant'] ?? 0),
            'awaiting_customer'   => (int) ($counts['awaiting_customer'] ?? 0),
        ]]);
    }

    public function dashboardSummary(Request $request)
    {
        $this->authorizeOperatorAccess($request);

        $now = now();

        $statusCounts = ConsultationEnquiry::query()
            ->whereHas('appointment')
            ->select('engagement_status')
            ->selectRaw('COUNT(*) as aggregate_count')
            ->groupBy('engagement_status')
            ->pluck('aggregate_count', 'engagement_status');

        $all = (int) $statusCounts->sum();
        $unassigned = Appointment::query()
            ->whereHas('consultationEnquiry')
            ->whereNull('assigned_user_id')
            ->count();

        $totals = [
            'all'                 => $all,
            'awaiting_consultant' => (int) ($statusCounts['awaiting_consultant'] ?? 0),
            'awaiting_customer'   => (int) ($statusCounts['awaiting_customer'] ?? 0),
            'completed'           => (int) ($statusCounts['completed'] ?? 0),
            'cancelled'           => (int) ($statusCounts['cancelled'] ?? 0),
            'unassigned'          => $unassigned,
        ];

        $attention = [
            'awaiting_customer_under_3_days' => 0,
            'awaiting_customer_3_to_7_days'  => 0,
            'awaiting_customer_over_7_days'  => 0,
            'awaiting_customer_unknown_age'  => 0,
        ];

        foreach ($this->awaitingCustomerAgeingByAppointmentId() as $bucket) {
            match ($bucket) {
                'under_3' => $attention['awaiting_customer_under_3_days']++,
                '3_to_7'  => $attention['awaiting_customer_3_to_7_days']++,
                'over_7'  => $attention['awaiting_customer_over_7_days']++,
                'unknown' => $attention['awaiting_customer_unknown_age']++,
            };
        }

        // Recent — cheap and genuinely useful, not added merely to fill
        // space. Creation uses Appointment::created_at directly (a
        // reliable timestamp needing no derivation); completions reuse the
        // same ActivityLog trail as the ageing buckets above, for the same
        // "don't trust updated_at" reason — distinct() so an
        // engagement completed, reopened, and completed again within the
        // window is still counted once.
        $createdLast7Days = Appointment::query()
            ->whereHas('consultationEnquiry')
            ->where('created_at', '>=', $now->copy()->subDays(7))
            ->count();

        $completedLast7Days = ActivityLog::query()
            ->where('action', 'consultation.engagement_status_changed')
            ->where('subject_type', Appointment::class)
            ->where('metadata->to', 'completed')
            ->where('created_at', '>=', $now->copy()->subDays(7))
            ->distinct('subject_id')
            ->count('subject_id');

        return response()->json([
            'totals'    => $totals,
            'attention' => $attention,
            'recent'    => [
                'created_last_7_days'   => $createdLast7Days,
                'completed_last_7_days' => $completedLast7Days,
            ],
        ]);
    }

    /**
     * The single source of truth for "how long has this consultation been
     * awaiting a customer response" — an appointment id => bucket key map
     * (`under_3`/`3_to_7`/`over_7`/`unknown`), shared by dashboardSummary()
     * (Batch 6A's ageing panel) and index()'s own `overdue_awaiting_customer`
     * quick-link filter below, so the two surfaces can never disagree on
     * what "overdue" means. See dashboardSummary()'s own docblock for why
     * this is derived exclusively from the ActivityLog trail, never
     * `updated_at`.
     *
     * @return array<int, string> appointment id => bucket key
     */
    private function awaitingCustomerAgeingByAppointmentId(): array
    {
        $appointmentIds = Appointment::query()
            ->whereHas('consultationEnquiry', fn ($q) => $q->where('engagement_status', 'awaiting_customer'))
            ->pluck('id');

        $latestTransitionAt = ActivityLog::query()
            ->where('action', 'consultation.engagement_status_changed')
            ->where('subject_type', Appointment::class)
            ->whereIn('subject_id', $appointmentIds)
            ->where('metadata->to', 'awaiting_customer')
            ->selectRaw('subject_id, MAX(created_at) as changed_at')
            ->groupBy('subject_id')
            ->pluck('changed_at', 'subject_id');

        $now = now();
        $buckets = [];
        foreach ($appointmentIds as $appointmentId) {
            $changedAt = $latestTransitionAt[$appointmentId] ?? null;
            if (!$changedAt) {
                $buckets[$appointmentId] = 'unknown';
                continue;
            }

            $daysElapsed = \Illuminate\Support\Carbon::parse($changedAt)->diffInDays($now);
            $buckets[$appointmentId] = $daysElapsed < 3 ? 'under_3' : ($daysElapsed <= 7 ? '3_to_7' : 'over_7');
        }

        return $buckets;
    }

    /** @return array<int, int> appointment ids currently in the `over_7` ageing bucket */
    private function overdueAwaitingCustomerAppointmentIds(): array
    {
        return array_keys(array_filter(
            $this->awaitingCustomerAgeingByAppointmentId(),
            fn ($bucket) => $bucket === 'over_7',
        ));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Write actions (Phase C2, Batch 4) — one explicit-intent method per
    // business action, never a generic "update" branching on a field. Each
    // delegates its actual state change to EngagementLifecycleService —
    // no transition logic is duplicated here.
    // ─────────────────────────────────────────────────────────────────────

    private function requireOperatorManage(Request $request, Appointment $appointment): void
    {
        if (!$this->authorizeOperatorManage($request, $appointment)) {
            abort(403, 'Access denied.');
        }
    }

    private function requireEnquiry(Appointment $appointment): ConsultationEnquiry
    {
        $enquiry = $appointment->consultationEnquiry;
        if (!$enquiry) {
            abort(404);
        }

        return $enquiry;
    }

    /**
     * Once an engagement is 'completed', internal notes and the customer
     * summary (draft or published) become immutable for anyone other than
     * a Super Admin — mirrors AiTelemetryIntegrityGuard's "protected once
     * terminal" pattern (§3 of the specification). Reopen first (Super
     * Admin only) to make further changes. Manual engagement-status
     * transitions don't need this guard themselves — they're already
     * rejected by EngagementLifecycleService's own transition rules once
     * an engagement reaches a terminal state.
     */
    private function assertNotLockedOrAbort(Request $request, ConsultationEnquiry $enquiry): void
    {
        if ($enquiry->engagement_status === 'completed' && !$request->user()->hasRole('Super Admin')) {
            abort(422, 'This engagement is completed — reopen it before making further changes.');
        }
    }

    private function respondWithOperator(Request $request, Appointment $appointment): \Illuminate\Http\JsonResponse
    {
        $appointment->load(self::OPERATOR_RELATIONS);
        $canEdit = $this->authorizeOperatorManage($request, $appointment);

        return response()->json(ConsultationPresenter::operator($appointment, $canEdit, $request->user()->hasRole('Super Admin')));
    }

    public function updateNotes(UpdateConsultationNotesRequest $request, Appointment $appointment)
    {
        $this->requireOperatorManage($request, $appointment);
        $enquiry = $this->requireEnquiry($appointment);
        $this->assertNotLockedOrAbort($request, $enquiry);

        $previousLength = mb_strlen((string) $enquiry->internal_notes);
        $newValue = $request->validated('internal_notes');
        $enquiry->update(['internal_notes' => $newValue]);

        ActivityLog::record(
            'consultation.internal_notes_updated',
            "Internal notes updated for consultation {$appointment->reference}.",
            $request->user(),
            $appointment,
            [
                'previous_length'  => $previousLength,
                'new_length'       => mb_strlen((string) $newValue),
                'new_content_hash' => hash('sha256', (string) $newValue),
            ],
            $appointment->project_id,
            $appointment->organization_id,
        );

        return $this->respondWithOperator($request, $appointment);
    }

    public function updateSummaryDraft(UpdateConsultationSummaryDraftRequest $request, Appointment $appointment)
    {
        $this->requireOperatorManage($request, $appointment);
        $enquiry = $this->requireEnquiry($appointment);
        $this->assertNotLockedOrAbort($request, $enquiry);

        $previousLength = mb_strlen((string) $enquiry->customer_summary_draft);
        $newDraft = $request->validated('customer_summary_draft');

        $updates = ['customer_summary_draft' => $newDraft];
        // An edit after a publish has already happened flips the "you have
        // unpublished changes" flag — the published copy itself is
        // untouched until an explicit publish/republish action (§4).
        if ($enquiry->customer_summary_published_at !== null) {
            $updates['customer_summary_needs_republish'] = true;
        }
        $enquiry->update($updates);

        ActivityLog::record(
            'consultation.summary_draft_updated',
            "Customer summary draft updated for consultation {$appointment->reference}.",
            $request->user(),
            $appointment,
            [
                'previous_length'  => $previousLength,
                'new_length'       => mb_strlen((string) $newDraft),
                'new_content_hash' => hash('sha256', (string) $newDraft),
            ],
            $appointment->project_id,
            $appointment->organization_id,
        );

        return $this->respondWithOperator($request, $appointment);
    }

    public function publishSummary(PublishConsultationSummaryRequest $request, Appointment $appointment)
    {
        $this->requireOperatorManage($request, $appointment);
        $enquiry = $this->requireEnquiry($appointment);
        $this->assertNotLockedOrAbort($request, $enquiry);

        $isRepublish = $enquiry->customer_summary_published_at !== null;
        $publishedValue = $enquiry->customer_summary_draft;

        $enquiry->update([
            'customer_summary_published'       => $publishedValue,
            'customer_summary_published_at'    => now(),
            'customer_summary_published_by'    => $request->user()->id,
            'customer_summary_needs_republish' => false,
        ]);

        ActivityLog::record(
            $isRepublish ? 'consultation.summary_republished' : 'consultation.summary_published',
            $isRepublish
                ? "Customer summary republished for consultation {$appointment->reference}."
                : "Customer summary published for consultation {$appointment->reference}.",
            $request->user(),
            $appointment,
            ['new_content_hash' => hash('sha256', (string) $publishedValue)],
            $appointment->project_id,
            $appointment->organization_id,
        );

        // First publish also completes the engagement (§2.4) — a republish
        // after reopen() completes it again; a republish with no reopen in
        // between (engagement already completed) is a no-op here, since
        // markCompleted() would reject an already-completed engagement —
        // guarded explicitly so publishing twice in a row without a reopen
        // never throws.
        if (in_array($enquiry->engagement_status, ['awaiting_consultant', 'awaiting_customer'], true)) {
            $this->engagementLifecycleService->markCompleted($enquiry, $request->user(), viaSummaryPublish: true);
        }

        // Communications Upgrade Batch 3 — replaces the old plain-text,
        // no-link, "available in your SureSign account" notification
        // (wrong for a public no-account customer) with the premium,
        // idempotent, correctly-linked email. See
        // ConsultationCommunicationService's own docblock for why this is
        // a migration of this one dispatch call, not a second email.
        SendConsultationCommunicationJob::dispatch($appointment->id, 'summary_published')->afterCommit();

        return $this->respondWithOperator($request, $appointment);
    }

    public function markAwaitingCustomer(MarkAwaitingCustomerRequest $request, Appointment $appointment)
    {
        $this->requireOperatorManage($request, $appointment);
        $enquiry = $this->requireEnquiry($appointment);

        try {
            $this->engagementLifecycleService->transitionManual($enquiry, 'awaiting_customer', $request->user());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        SendConsultationEmailJob::dispatch($enquiry->id, 'awaiting_customer')->afterCommit();

        return $this->respondWithOperator($request, $appointment);
    }

    public function markAwaitingConsultant(MarkAwaitingConsultantRequest $request, Appointment $appointment)
    {
        $this->requireOperatorManage($request, $appointment);
        $enquiry = $this->requireEnquiry($appointment);

        try {
            $this->engagementLifecycleService->transitionManual($enquiry, 'awaiting_consultant', $request->user());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->respondWithOperator($request, $appointment);
    }

    public function markCompleted(MarkConsultationCompletedRequest $request, Appointment $appointment)
    {
        $this->requireOperatorManage($request, $appointment);
        $enquiry = $this->requireEnquiry($appointment);

        try {
            $this->engagementLifecycleService->markCompleted($enquiry, $request->user(), viaSummaryPublish: false);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->respondWithOperator($request, $appointment);
    }

    public function reopen(ReopenConsultationRequest $request, Appointment $appointment)
    {
        if (!$request->user()->hasRole('Super Admin')) {
            abort(403, 'Only Super Admin may reopen a completed engagement.');
        }
        $enquiry = $this->requireEnquiry($appointment);

        try {
            $this->engagementLifecycleService->reopen($enquiry, $request->user());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->respondWithOperator($request, $appointment);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Project linkage (Phase C2, Batch 5) — see
    // internal-docs/commercial/suresign-consultancy-phase-c2-specification-v1.md
    // §6 for the full execution-order rationale and the confirmed
    // same-organisation rule.
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Handles both a first link and replacing an existing one — the same
     * operation (set project_id) with the same validation, so a second
     * explicit-intent endpoint for "change" would just duplicate this one.
     *
     * Execution order is deliberate, not incidental:
     *   1. authorize() — an unassigned Admin/Client learns NOTHING about
     *      whether the requested project exists, is soft-deleted, or
     *      belongs to another organisation; they simply get 403.
     *   2. resolve + validate the project exists and is not soft-deleted.
     *   3. enforce same-organisation, unconditionally — applies to Super
     *      Admin exactly as it applies to an assigned Admin; this is a
     *      data-integrity rule, not a permission the highest role can waive.
     *   4. idempotency check — re-linking the SAME project is a no-op:
     *      200, unchanged state, no write, no activity event.
     *   5. only on a genuine change: persist + log.
     */
    public function linkProject(LinkConsultationProjectRequest $request, Appointment $appointment)
    {
        $this->requireOperatorManage($request, $appointment);
        $enquiry = $this->requireEnquiry($appointment);

        $requestedProjectId = $request->validated('project_id');
        $project = Project::withTrashed()->find($requestedProjectId);
        if (!$project || $project->trashed()) {
            return response()->json(['message' => 'That project could not be found.'], 422);
        }

        if ($project->organization_id !== $appointment->organization_id) {
            return response()->json(['message' => 'This project belongs to a different organisation.'], 422);
        }

        if ($appointment->project_id === $project->id) {
            // Idempotent no-op — re-linking the currently-linked project
            // writes nothing and logs nothing, per the confirmed execution
            // order (never a misleading "project_changed" event for a
            // request that changed nothing).
            return $this->respondWithOperator($request, $appointment);
        }

        $previousProject = $appointment->project;
        $appointment->update(['project_id' => $project->id]);

        ActivityLog::record(
            $previousProject ? 'consultation.project_changed' : 'consultation.project_linked',
            $previousProject
                ? "Consultation {$appointment->reference} relinked to project {$project->name}."
                : "Consultation {$appointment->reference} linked to project {$project->name}.",
            $request->user(),
            $appointment,
            $previousProject
                ? [
                    'previous_project_id'   => $previousProject->id,
                    'previous_project_code' => $previousProject->code,
                    'new_project_id'        => $project->id,
                    'new_project_code'      => $project->code,
                ]
                : [
                    'project_id'   => $project->id,
                    'project_code' => $project->code,
                    'project_name' => $project->name,
                ],
            $project->id,
            $appointment->organization_id,
        );

        return $this->respondWithOperator($request, $appointment);
    }

    public function unlinkProject(UnlinkConsultationProjectRequest $request, Appointment $appointment)
    {
        $this->requireOperatorManage($request, $appointment);
        $this->requireEnquiry($appointment);

        $project = $appointment->project;
        if (!$project) {
            // Already unlinked — idempotent no-op, same reasoning as linkProject().
            return $this->respondWithOperator($request, $appointment);
        }

        $appointment->update(['project_id' => null]);

        ActivityLog::record(
            'consultation.project_unlinked',
            "Consultation {$appointment->reference} unlinked from project {$project->name}.",
            $request->user(),
            $appointment,
            [
                'project_id'   => $project->id,
                'project_code' => $project->code,
                'project_name' => $project->name,
            ],
            null,
            $appointment->organization_id,
        );

        return $this->respondWithOperator($request, $appointment);
    }

    /**
     * The Project-side view — a lightweight, read-only, Consultancy-owned
     * list of consultations linked to a given project, for display inside
     * the Project's own workspace (/app/projects/{id}). Gated by
     * authorizeOperatorAccess() (platform-wide read, matching Batch 3) —
     * NOT authorizeOperatorManage() — since this is a read surface, not a
     * consultation-specific write action.
     *
     * Never returns a full operator() payload — see
     * ConsultationPresenter::projectSummary() for the deliberately
     * narrower whitelist (no internal notes, no summary content, no
     * attendee contact detail, no activity log).
     */
    public function projectConsultations(Request $request, Project $project)
    {
        $this->authorizeOperatorAccess($request);

        $canView = true; // authorizeOperatorAccess() already gates the whole endpoint identically for every caller who reaches it.

        $appointments = $project->appointments()
            ->whereHas('consultationEnquiry')
            ->with(['assignedUser:id,name', 'consultationEnquiry.consultancyService'])
            ->latest('starts_at')
            ->paginate($request->integer('per_page', 25));

        $appointments->getCollection()->transform(
            fn (Appointment $appointment) => ConsultationPresenter::projectSummary($appointment, $canView)
        );

        return response()->json($appointments);
    }
}
