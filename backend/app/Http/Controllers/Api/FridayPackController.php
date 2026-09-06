<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FridayPack;
use App\Models\Project;
use App\Models\FridayPackSectionDeclaration;
use App\Services\FridayPack\FridayPackGenerationService;
use App\Services\FridayPack\FridayPackLifecycleService;
use App\Services\FridayPack\FridayPackPdfService;
use App\Services\FridayPack\FridayPackPeriodResolver;
use App\Services\FridayPack\FridayPackReadinessService;
use App\Services\FridayPack\FridayPackSectionDeclarationService;
use App\Services\Entitlements\FeatureGate;
use App\Services\ProjectActivityService;
use App\Support\Entitlements\Feature;
use App\Support\FridayPack\FridayPackNotReadyException;
use App\Support\FridayPack\FridayPackReadinessMatrix;
use App\Support\FridayPack\FridayPackSchemaVersion;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Automated Friday Pack, V1B/V1C/V1D — mirrors ToolboxTalkController/
 * QaReportController's exact authorization/parent-integrity structure.
 * generate()/regenerate() delegate entirely to
 * FridayPackGenerationService; pdf() delegates entirely to
 * FridayPackPdfService; submitForReview()/markReviewed()/returnToDraft()/
 * approve() delegate entirely to FridayPackLifecycleService — this
 * controller never builds a snapshot, resolves a period, renders a PDF,
 * or applies lifecycle transition rules itself.
 */
class FridayPackController extends Controller
{
    private function authorize(Request $request, Project|FridayPack $subject): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) return;
        if ($user->organization_id !== $subject->organization_id) abort(403, 'Access denied.');
    }

    private function authorizeProjectFridayPack(Request $request, Project $project, FridayPack $fridayPack): void
    {
        $this->authorize($request, $fridayPack);
        if ($fridayPack->project_id !== $project->id) {
            abort(404, 'Friday Pack not found for this project.');
        }
    }

    /**
     * Friday Pack Plan Entitlement Enforcement — Entitlement UX phase.
     * Read-only status check so the frontend can show the upgrade state
     * on page load rather than only discovering it from a failed
     * mutation. Deliberately the smallest possible extension: calls the
     * exact same `FeatureGate::allows()` `EnsureFeatureIsEntitled` itself
     * uses — this never duplicates entitlement logic, it only exposes
     * the same answer as a read. Mirrors that middleware's own Super
     * Admin/Admin bypass (a platform operator is never shown an upgrade
     * warning for a feature they may legitimately operate).
     */
    public function entitlement(Request $request, Project $project, FeatureGate $gate)
    {
        $this->authorize($request, $project);

        $user = $request->user();
        $isPlatformOperator = $user->hasRole('Super Admin') || $user->hasRole('Admin');

        return response()->json([
            'entitled' => $isPlatformOperator || $gate->allows($project->organization, Feature::FRIDAY_PACKS),
            'is_platform_operator' => $isPlatformOperator,
        ]);
    }

    public function index(Request $request, Project $project)
    {
        $this->authorize($request, $project);

        $query = FridayPack::where('project_id', $project->id)
            ->with('generatedBy:id,name')
            ->select(['id', 'project_id', 'week_ending', 'period_start', 'period_end', 'status', 'generated_at', 'generated_by', 'generation_source', 'reviewed_at']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->latest('week_ending')->paginate(25));
    }

    public function show(Request $request, Project $project, FridayPack $fridayPack, FridayPackReadinessService $readinessService)
    {
        $this->authorizeProjectFridayPack($request, $project, $fridayPack);

        $data = $fridayPack->load(['generatedBy:id,name', 'reviewedBy:id,name', 'approvedBy:id,name', 'sentBy:id,name'])->toArray();

        // R1F.2 — derived readiness, schema-2 only (see
        // FridayPackReadinessService's own schema-version branch); the
        // key is omitted entirely for a schema-1 pack rather than a
        // vacuous ready:true block, since readiness has no meaning there.
        if ((int) ($fridayPack->snapshot_json['schema_version'] ?? 1) === FridayPackSchemaVersion::CURRENT_VERSION) {
            $data['readiness'] = $readinessService->evaluate($fridayPack);
        }

        return response()->json($data);
    }

    /**
     * R1F.2 — Content Readiness + Weekly Declarations. Every mutation
     * validation lives in FridayPackSectionDeclarationService; this
     * controller only authorizes the parent Project/FridayPack and
     * shapes the request/response, exactly like every other Friday Pack
     * mutation endpoint here.
     */
    public function declareSection(Request $request, Project $project, FridayPack $fridayPack, FridayPackSectionDeclarationService $service, FridayPackReadinessService $readinessService)
    {
        $this->authorizeProjectFridayPack($request, $project, $fridayPack);

        $validated = $request->validate([
            'section_key' => 'required|string',
            'subsection_key' => 'nullable|string',
            'declaration' => ['required', 'string', Rule::in(FridayPackSectionDeclaration::DECLARATIONS)],
            'note' => 'nullable|string|max:2000',
        ]);

        try {
            $service->declare(
                $fridayPack,
                $request->user(),
                $validated['section_key'],
                $validated['subsection_key'] ?? FridayPackReadinessMatrix::TOP_LEVEL,
                $validated['declaration'],
                $validated['note'] ?? null,
            );
        } catch (\RuntimeException $e) {
            abort(409, $e->getMessage());
        }

        return response()->json(['readiness' => $readinessService->evaluate($fridayPack->fresh())]);
    }

    public function clearSectionDeclaration(Request $request, Project $project, FridayPack $fridayPack, FridayPackSectionDeclarationService $service, FridayPackReadinessService $readinessService)
    {
        $this->authorizeProjectFridayPack($request, $project, $fridayPack);

        $validated = $request->validate([
            'section_key' => 'required|string',
            'subsection_key' => 'nullable|string',
        ]);

        try {
            $service->clear(
                $fridayPack,
                $request->user(),
                $validated['section_key'],
                $validated['subsection_key'] ?? FridayPackReadinessMatrix::TOP_LEVEL,
            );
        } catch (\RuntimeException $e) {
            abort(409, $e->getMessage());
        }

        return response()->json(['readiness' => $readinessService->evaluate($fridayPack->fresh())]);
    }

    /**
     * Manual generation only in V1B — no scheduler dispatches this.
     * Creates a new draft, or regenerates an existing draft for the same
     * week_ending (see FridayPackGenerationService::generate() for the
     * full idempotency/regeneration contract).
     */
    public function store(Request $request, Project $project, FridayPackGenerationService $service)
    {
        $this->authorize($request, $project);

        $validated = $request->validate(['week_ending' => 'required|date']);

        if (!FridayPackPeriodResolver::isFriday($validated['week_ending'], $project->organization)) {
            throw ValidationException::withMessages(['week_ending' => 'Week ending must be a Friday.']);
        }

        try {
            $pack = $service->generate($project, $validated['week_ending'], $request->user());
        } catch (\RuntimeException $e) {
            abort(409, $e->getMessage());
        }

        return response()->json($pack->load(['generatedBy:id,name', 'reviewedBy:id,name', 'approvedBy:id,name', 'sentBy:id,name']), 201);
    }

    /**
     * Explicit regenerate action — same underlying service call as
     * store(), exposed as its own endpoint per the V1B API spec so the
     * frontend's "Regenerate Draft" action doesn't have to resubmit
     * week_ending redundantly.
     */
    public function regenerate(Request $request, Project $project, FridayPack $fridayPack, FridayPackGenerationService $service)
    {
        $this->authorizeProjectFridayPack($request, $project, $fridayPack);

        try {
            $pack = $service->generate($project, $fridayPack->week_ending->toDateString(), $request->user());
        } catch (\RuntimeException $e) {
            abort(409, $e->getMessage());
        }

        return response()->json($pack->load(['generatedBy:id,name', 'reviewedBy:id,name', 'approvedBy:id,name', 'sentBy:id,name']));
    }

    /**
     * Draft-only edit of manual commentary — never accepts
     * organization_id/generated_by/status/snapshot_json/
     * settings_snapshot_json/period_start/period_end from the client; all
     * server-controlled. FridayPackIntegrityGuard additionally refuses any
     * of these once the pack is approved/sent, defence in depth beyond
     * this validation layer.
     */
    public function update(Request $request, Project $project, FridayPack $fridayPack)
    {
        $this->authorizeProjectFridayPack($request, $project, $fridayPack);

        // V1D: commentary is editable only while Draft — once submitted
        // for review, content is under review and must not silently
        // change underneath the reviewer. FridayPackIntegrityGuard alone
        // is insufficient here (it only protects once approved/sent, not
        // ready_for_review), so this is enforced explicitly, server-side
        // — never relying on the frontend merely hiding the form.
        if ($fridayPack->status !== 'draft') {
            abort(409, 'Commentary can only be edited while the Friday Pack is a draft.');
        }

        $validated = $request->validate([
            'executive_summary'     => 'nullable|string|max:10000',
            'progress_commentary'   => 'nullable|string|max:10000',
            'key_concerns'          => 'nullable|string|max:10000',
            'next_week_priorities'  => 'nullable|string|max:10000',
            // R1C — the confirmed Weekly Summary text. Source suggestions
            // (see weeklySummarySources() below) never overwrite this
            // automatically; only an explicit save here does.
            'weekly_summary'        => 'nullable|string|max:10000',
            // R1D — the confirmed Site Issues/Delays/Risks and Look Ahead
            // texts. Same contract as weekly_summary: source suggestions
            // never auto-overwrite, only an explicit save here does.
            // report_number is deliberately NEVER accepted here — it is
            // allocated exactly once, server-side only (see
            // FridayPackGenerationService).
            'site_issues_summary'   => 'nullable|string|max:10000',
            'look_ahead'            => 'nullable|string|max:10000',
        ]);

        $fridayPack->fill($validated);
        $commentaryChanged = $fridayPack->isDirty([
            'executive_summary', 'progress_commentary', 'key_concerns', 'next_week_priorities',
            'weekly_summary', 'site_issues_summary', 'look_ahead',
        ]);

        // V1C stale-PDF rule: an actual commentary edit changes
        // report-visible content, so any current PDF no longer reflects
        // it — clear the pointer in the SAME save, never delete the
        // historical Document/file itself. A no-op update (identical
        // values resubmitted) leaves a valid current PDF untouched.
        if ($commentaryChanged) {
            $fridayPack->pdf_document_id = null;
        }

        $fridayPack->save();

        ProjectActivityService::record(
            $project,
            $request->user(),
            'friday_pack_commentary_updated',
            'Friday Pack commentary updated for week ending ' . $fridayPack->week_ending->toDateString(),
            null,
            $fridayPack,
        );

        return response()->json($fridayPack->fresh()->load(['generatedBy:id,name', 'reviewedBy:id,name', 'approvedBy:id,name', 'sentBy:id,name']));
    }

    /**
     * Draft-only delete (V1B decision) — historical reporting records
     * should not casually disappear once generated past draft; an
     * approved/sent pack can never be deleted through normal project UI.
     */
    public function destroy(Request $request, Project $project, FridayPack $fridayPack)
    {
        $this->authorizeProjectFridayPack($request, $project, $fridayPack);

        if ($fridayPack->status !== 'draft') {
            abort(409, 'Only a draft Friday Pack may be deleted.');
        }

        $fridayPack->delete();

        return response()->json(null, 204);
    }

    /**
     * Generates (or regenerates) the CURRENT PDF for this Friday Pack —
     * V1C. Reused for both the initial "Generate PDF" and explicit
     * "Regenerate PDF" actions (FridayPackPdfService itself has no
     * concept of "already has one"; it simply produces a new Document and
     * repoints pdf_document_id). Never automatically triggered by
     * viewing the detail page — only this explicit action.
     */
    public function pdf(Request $request, Project $project, FridayPack $fridayPack, FridayPackPdfService $service)
    {
        $this->authorizeProjectFridayPack($request, $project, $fridayPack);

        try {
            $document = $service->generate($fridayPack, $request->user());
        } catch (\App\Support\FridayPack\FridayPackDeliveryLockedException $e) {
            // V1F: the PDF-lock-after-delivery-start rejection is a normal
            // conflict, not a system failure — surface its real message.
            // Deliberately a distinct exception type, never a plain
            // RuntimeException, so a genuine PDF-generation failure below
            // still surfaces as 500 exactly as before this phase.
            abort(409, $e->getMessage());
        } catch (\App\Support\FridayPack\FridayPackUnsupportedSchemaVersionException $e) {
            // R1A.1: a schema-2 (realigned) pack's PDF is not yet
            // supported by the still-schema-1-only template — an
            // expected, controlled "not yet available" response, not a
            // system failure.
            abort(409, $e->getMessage());
        } catch (\Throwable $e) {
            \Log::error("Friday Pack PDF generation failed for pack #{$fridayPack->id}: " . $e->getMessage());
            abort(500, 'Failed to generate the Friday Pack PDF. Please try again.');
        }

        return response()->json([
            'document'   => $document,
            'friday_pack' => $fridayPack->fresh(),
        ], 201);
    }

    /**
     * R1C — GET .../weekly-summary-sources. Read-only, deterministic
     * reference material for the Weekly Summary section; never persisted,
     * never auto-composed into FridayPack.weekly_summary. Mirrors
     * FridayPackPhotoSelectionController::candidates()'s exact shape.
     */
    public function weeklySummarySources(Request $request, Project $project, FridayPack $fridayPack, \App\Services\FridayPack\FridayPackWeeklySummarySourceService $service)
    {
        $this->authorizeProjectFridayPack($request, $project, $fridayPack);

        $period = [
            'period_start' => $fridayPack->period_start->toDateString(),
            'period_end'   => $fridayPack->period_end->toDateString(),
        ];

        $sources = $service->sources($project, $period);

        return response()->json([
            'sources'                   => $sources,
            'source_site_report_count'  => count($sources),
        ]);
    }

    /**
     * R1D — GET .../site-issues-sources. Read-only, deterministic
     * reference material (Site Report issues + week-scoped DelayEvent
     * references); never persisted, never auto-composed into
     * FridayPack.site_issues_summary.
     */
    public function siteIssuesSources(Request $request, Project $project, FridayPack $fridayPack, \App\Services\FridayPack\FridayPackSiteIssuesSourceService $service)
    {
        $this->authorizeProjectFridayPack($request, $project, $fridayPack);

        $period = [
            'period_start' => $fridayPack->period_start->toDateString(),
            'period_end'   => $fridayPack->period_end->toDateString(),
        ];

        return response()->json([
            'sources'                  => $service->sources($project, $period),
            'delay_event_references'   => $service->delayEventReferences($project, $period),
        ]);
    }

    /**
     * R1D — GET .../look-ahead-sources. Read-only, deterministic upcoming
     * programme reference material (next Monday-Friday window only, never
     * the whole Programme); never persisted, never auto-composed into
     * FridayPack.look_ahead.
     */
    public function lookAheadSources(Request $request, Project $project, FridayPack $fridayPack, \App\Services\FridayPack\FridayPackLookAheadSourceService $service)
    {
        $this->authorizeProjectFridayPack($request, $project, $fridayPack);

        $periodEnd = $fridayPack->period_end->toDateString();

        return response()->json([
            'window'      => $service->nextWeekWindow($periodEnd),
            'milestones'  => $service->sources($project, $periodEnd),
        ]);
    }

    // ── Review / Approval lifecycle (V1D) ─────────────────────────────────
    // Every action below: authorize → delegate entirely to
    // FridayPackLifecycleService → map an invalid-transition
    // RuntimeException to 409. No transition rule, timestamp, or actor is
    // ever set here — the service is the sole authority.

    public function submitForReview(Request $request, Project $project, FridayPack $fridayPack, FridayPackLifecycleService $service)
    {
        $this->authorizeProjectFridayPack($request, $project, $fridayPack);

        try {
            $pack = $service->submitForReview($fridayPack, $request->user());
        } catch (FridayPackNotReadyException $e) {
            // R1F.2 — the structured blocker payload, never a flat
            // "Friday Pack incomplete" string. No internal model/field
            // names — every reason string is customer-safe prose
            // resolved from FridayPackReadinessMatrix's own labels.
            return response()->json([
                'message' => $e->getMessage(),
                'readiness' => ['ready' => false, 'blockers' => $e->blockers()],
            ], 409);
        } catch (\RuntimeException $e) {
            abort(409, $e->getMessage());
        }

        return response()->json($pack->load(['generatedBy:id,name', 'reviewedBy:id,name', 'approvedBy:id,name', 'sentBy:id,name']));
    }

    public function markReviewed(Request $request, Project $project, FridayPack $fridayPack, FridayPackLifecycleService $service)
    {
        $this->authorizeProjectFridayPack($request, $project, $fridayPack);

        try {
            $pack = $service->markReviewed($fridayPack, $request->user());
        } catch (\RuntimeException $e) {
            abort(409, $e->getMessage());
        }

        return response()->json($pack->load(['generatedBy:id,name', 'reviewedBy:id,name', 'approvedBy:id,name', 'sentBy:id,name']));
    }

    public function returnToDraft(Request $request, Project $project, FridayPack $fridayPack, FridayPackLifecycleService $service)
    {
        $this->authorizeProjectFridayPack($request, $project, $fridayPack);

        try {
            $pack = $service->returnToDraft($fridayPack, $request->user());
        } catch (\RuntimeException $e) {
            abort(409, $e->getMessage());
        }

        return response()->json($pack->load(['generatedBy:id,name', 'reviewedBy:id,name', 'approvedBy:id,name', 'sentBy:id,name']));
    }

    public function approve(Request $request, Project $project, FridayPack $fridayPack, FridayPackLifecycleService $service)
    {
        $this->authorizeProjectFridayPack($request, $project, $fridayPack);

        try {
            $pack = $service->approve($fridayPack, $request->user());
        } catch (\RuntimeException $e) {
            abort(409, $e->getMessage());
        }

        return response()->json($pack->load(['generatedBy:id,name', 'reviewedBy:id,name', 'approvedBy:id,name', 'sentBy:id,name']));
    }
}
