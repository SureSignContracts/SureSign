<?php

namespace App\Services\FridayPack;

use App\Models\ContractProgrammeMilestone;
use App\Models\ContractRisk;
use App\Models\DelayEvent;
use App\Models\DeliveryDocument;
use App\Models\Drawing;
use App\Models\DrawingRevision;
use App\Models\EotRequest;
use App\Models\FridayPack;
use App\Models\MeetingMinutes;
use App\Models\Project;
use App\Models\QaReport;
use App\Models\Rfi;
use App\Models\SiteDiary;
use App\Models\Snag;
use App\Models\ToolboxTalk;
use App\Models\Variation;
use App\Services\Commercial\CommercialAggregationService;
use App\Services\OperationalIntelligenceService;
use App\Services\UpcomingActionsService;
use App\Support\FridayPack\FridayPackSchemaVersion;
use App\Support\FridayPack\FridayPackSections;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Automated Friday Pack, V1B — the ONE authoritative collector of
 * deterministic report data. No controller rebuilds sections
 * independently; no frontend recalculates report metrics; no AI is
 * involved anywhere in this class.
 *
 * Every section is either read directly from a model (never mutated) or
 * delegated to an existing authoritative service
 * (CommercialAggregationService, OperationalIntelligenceService,
 * UpcomingActionsService) — this class never recomputes a figure those
 * services already own.
 *
 * **R1A — Friday Pack Realignment (2026-08-31).** `collectSection()`
 * below now only actively matches on the authentic
 * App\Support\FridayPack\FridayPackSections keys — see that class's own
 * docblock for the full realignment rationale. The private
 * programme()/toolboxTalks()/siteReports()/risks()/rfis()/variations()/
 * commercial()/delaysEot()/meetingsActions()/deliveryDocuments()/
 * drawings()/qaSnagging()/upcomingActionsSection() methods below are
 * DELIBERATELY LEFT INTACT AND UNMODIFIED even though most are no longer
 * called from collectSection()'s match arms — they are the preserved,
 * working candidates for a future, separate Weekly Project Report /
 * Client Weekly Report, not dead code to be deleted. Only toolboxTalks()
 * remains wired into the active Friday Pack path (its key, `toolbox_talks`,
 * is unchanged — Toolbox Talks was never a management-report section).
 *
 * Every authentic key with no collector implementation yet (RAMS, site
 * inductions, incidents, H&S inspections, permits, plant/equipment) falls
 * through collectSection()'s existing `default` arm and is recorded as an
 * honest empty stub (`['count' => 0, 'items' => []]`) — never fabricated
 * content. Each of these gets its own real collector in a later, dedicated
 * Friday Pack content phase (the H&S domain phase).
 *
 * **R1C — Weekly Summary + Workforce (2026-08-31).** Both now have real
 * collectors — FridayPackWeeklySummarySourceService (deterministic Site
 * Report source material + the pack's own confirmed
 * `weekly_summary` text) and FridayPackWorkforceService (conflict-safe
 * Mon-Fri workforce aggregation). See both classes' own docblocks.
 *
 * **R1D — Report Information, Materials Delivered, Site Issues, Look
 * Ahead (2026-09-01).** All four now have real collectors —
 * FridayPackReportInformationService, FridayPackMaterialsSourceService,
 * FridayPackSiteIssuesSourceService, FridayPackLookAheadSourceService. Sign
 * Off is a deliberate, explicit EXCEPTION — it is NEVER collected here
 * (see collectSection()'s own `sign_off` comment) because it is report
 * lifecycle metadata, not frozen source content; the frontend/presenter
 * reads it live from FridayPack itself.
 *
 * **R1E.1 — RAMS + Permits (2026-09-01).** Both now have real collectors
 * — FridayPackComplianceDocumentService, reusing the EXISTING
 * App\Models\DeliveryDocument register (never a new RAMS/Permit model —
 * see the R1E audit's own finding that DeliveryDocument already covers
 * both). `permits_inspections`' `inspections` array stays deliberately
 * empty (`[]`) until the separate, not-yet-approved Statutory Inspections
 * domain is implemented — never manufactured from DeliveryDocument rows.
 *
 * **R1E.2A — Site Inductions (2026-09-01).** The first of the six H&S
 * domain tables approved by the R1E.2 corrected schema checkpoint — real
 * collector via FridayPackSiteInductionSourceService, reading the new
 * App\Models\SiteInduction register. H&S Inspections/Plant & Equipment/
 * Statutory Inspections remain unimplemented (empty stubs).
 *
 * **R1E.2B — Incidents / Accidents / Near Misses (2026-09-01).** Real
 * collector via FridayPackIncidentSourceService, reading the new
 * App\Models\Incident register. The first Friday Pack source needing
 * real UTC-vs-local timezone conversion (via the existing
 * App\Services\TimezoneResolver — never a new timezone system) since
 * `Incident::$occurred_at` is a genuine datetime, unlike every prior
 * source's plain DATE columns.
 *
 * **R1E.2C — H&S Inspections (2026-09-01).** Real collector via
 * FridayPackHsInspectionSourceService, reading the new
 * App\Models\HsInspection register — deliberately never `QaReport`
 * relabelled. Statutory Inspections remains unimplemented.
 *
 * **R1E.2D — Plant & Equipment (2026-09-01).** Real collector via
 * FridayPackPlantEquipmentSourceService, reading the new
 * App\Models\PlantDeployment register (never `PlantItem::$status`).
 *
 * **R1E.2E — Statutory Inspections (2026-09-01).** The sixth and final
 * approved H&S domain source. Real collector via
 * FridayPackStatutoryInspectionSourceService, reading the new
 * App\Models\StatutoryInspection register, populating only the
 * previously-empty `inspections` sub-array of `permits_inspections` — the
 * `permits` half of that section (R1E.1) is untouched. Every H&S/
 * compliance source domain identified by the R1E audit now has a real
 * collector.
 *
 * **R1A.1 — Snapshot Schema Version Boundary (2026-08-31).** `collect()`
 * now explicitly writes
 * `App\Support\FridayPack\FridayPackSchemaVersion::CURRENT_VERSION` (2),
 * never a hardcoded literal — resolving the schema-meaning-collision risk
 * R1A itself flagged. A pre-R1A `FridayPack` row's `snapshot_json` still
 * says `schema_version: 1` and keeps that exact historical meaning
 * forever (this class never rewrites an existing row); every snapshot
 * `collect()` produces from this point forward — new generation,
 * scheduled generation, or an explicit Draft regeneration of a legacy
 * schema-1 pack — is schema 2, even while most of schema 2's sections are
 * still empty stubs pending their own content phase. See
 * FridayPackSchemaVersion's own docblock for the full legacy-vs-current
 * contract definitions, and FridayPackPdfService for why a schema-2
 * snapshot is deliberately refused PDF generation until a schema-2-aware
 * template exists.
 */
class FridayPackSnapshotService
{
    public function __construct(
        private CommercialAggregationService $commercial,
        private OperationalIntelligenceService $intelligence,
        private UpcomingActionsService $upcomingActions,
        private FridayPackWeeklySummarySourceService $weeklySummarySources,
        private FridayPackWorkforceService $workforce,
        private FridayPackReportInformationService $reportInformation,
        private FridayPackMaterialsSourceService $materialsSources,
        private FridayPackSiteIssuesSourceService $siteIssuesSources,
        private FridayPackLookAheadSourceService $lookAheadSources,
        private FridayPackComplianceDocumentService $complianceDocuments,
        private FridayPackSiteInductionSourceService $siteInductionSources,
        private FridayPackIncidentSourceService $incidentSources,
        private FridayPackHsInspectionSourceService $hsInspectionSources,
        private FridayPackPlantEquipmentSourceService $plantEquipmentSources,
        private FridayPackStatutoryInspectionSourceService $statutoryInspectionSources,
    ) {}

    /**
     * @param  array{week_ending: string, period_start: string, period_end: string, timezone: string}  $period
     * @param  string[]  $includedSections
     * @param  FridayPack|null  $existingPack  R1B — the pack this snapshot
     *   is being (re)collected FOR, when it already exists (i.e.
     *   regeneration, or a fresh scheduled/manual generation that already
     *   has an id at collect() time). Null for first-time generation,
     *   where the pack has no id yet and therefore cannot possibly have
     *   any persisted photo selections — `site_photographs` correctly
     *   collects empty in that case, never a live-data guess.
     * @param  array{prepared_by?: string, prepared_at?: string, report_number?: ?string}  $reportMeta
     *   R1D — Report Information's "point-in-time" fields the CALLER
     *   (FridayPackGenerationService) resolves BEFORE calling collect(),
     *   rather than this service reading them off `$existingPack`. This
     *   matters because `$existingPack->generated_at`/`generated_by`
     *   still hold the PREVIOUS generation's values at collect()-time —
     *   FridayPackGenerationService only assigns the new ones onto the
     *   pack AFTER this method returns (see its own docblock) — so
     *   reading them from `$existingPack` here would freeze stale values
     *   on every regeneration. `report_number` is passed the same way
     *   purely for consistency, even though the pack's own persisted
     *   value would already be correct after first-time allocation.
     */
    public function collect(Project $project, array $period, array $includedSections, ?FridayPack $existingPack = null, array $reportMeta = []): array
    {
        $start = $period['period_start'];
        $end   = $period['period_end'];

        $sections = [];
        foreach (FridayPackSections::ALL as $key) {
            if (!in_array($key, $includedSections, true)) {
                continue;
            }
            $sections[$key] = $this->collectSection($key, $project, $start, $end, $existingPack, $reportMeta, $period['timezone'] ?? 'UTC');
        }

        return [
            'schema_version' => FridayPackSchemaVersion::CURRENT_VERSION,
            'project'  => $this->projectIdentity($project),
            'period'   => $period,
            'sections' => $sections,
            'generated_meta' => [
                'generated_at_utc' => now()->toIso8601String(),
            ],
        ];
    }

    /**
     * `date`/`datetime`-cast columns in this codebase are written back to
     * storage using the connection's full datetime format (e.g.
     * "2026-08-21 00:00:00"), not the plain "Y-m-d" a caller naturally
     * passes to whereBetween() — a mismatch that is silently masked by
     * MySQL's real DATE column type (which truncates regardless) but is
     * real and reproducible on the SQLite test database, where date
     * columns are plain TEXT. Every date-range query in this class uses
     * this helper rather than a bare [$start, $end] pair, so a real
     * row at the exact end-of-day boundary is never silently excluded on
     * either database.
     */
    private function dateRange(string $start, string $end): array
    {
        return ["{$start} 00:00:00", "{$end} 23:59:59"];
    }

    private function collectSection(string $key, Project $project, string $start, string $end, ?FridayPack $existingPack, array $reportMeta = [], string $timezone = 'UTC'): array
    {
        return match ($key) {
            // Real collector, authentic key — Toolbox Talks V1A.
            FridayPackSections::TOOLBOX_TALKS => $this->toolboxTalks($project, $start, $end),
            // R1B — reads the pack's OWN persisted photo selections
            // (App\Models\FridayPackPhotoSelection), never live discovery
            // data. Empty for a pack that doesn't exist yet (first-time
            // generation) — nothing could have been selected against an
            // id that doesn't exist yet.
            FridayPackSections::SITE_PHOTOGRAPHS => $existingPack
                ? \App\Support\FridayPack\FridayPackPhotoSelectionPresenter::sectionFor($existingPack)
                : ['count' => 0, 'items' => []],
            // R1C — the confirmed FridayPack.weekly_summary text (null for
            // a pack that doesn't exist yet — nothing could have been
            // confirmed against an id that doesn't exist yet), plus a
            // count of the Site Reports that actually contributed
            // deterministic source material. Never every raw Site Report
            // narrative — see FridayPackWeeklySummarySourceService.
            FridayPackSections::WEEKLY_SUMMARY => [
                'text' => $existingPack?->weekly_summary,
                'source_site_report_count' => $this->weeklySummarySources->sourceCount(
                    $project, ['period_start' => $start, 'period_end' => $end]
                ),
            ],
            // R1C — deterministic Mon-Fri workforce aggregation. See
            // FridayPackWorkforceService for the full conflict-safe
            // contract.
            FridayPackSections::WORKFORCE => $this->workforce->aggregate(
                $project, ['period_start' => $start, 'period_end' => $end]
            ),
            // R1D — Report Information. Party/identity fields from
            // FridayPackReportInformationService (fails to null rather
            // than guesses — see that class); week dates and
            // prepared-by/report-number come directly from this call's
            // own period/$reportMeta (never live-re-derived at render
            // time — see collect()'s own docblock on why $reportMeta is
            // threaded through explicitly).
            FridayPackSections::REPORT_INFORMATION => array_merge(
                $this->reportInformation->projectAndPartyFields($project),
                [
                    'week_commencing' => $start,
                    'week_ending'     => $end,
                    'prepared_by'     => $reportMeta['prepared_by'] ?? null,
                    'prepared_at'     => $reportMeta['prepared_at'] ?? null,
                    'report_number'   => $reportMeta['report_number'] ?? null,
                ]
            ),
            // R1D — Materials Delivered. Deterministic Mon-Fri source
            // list only; no confirmed-narrative field exists for this
            // section (R1D's own decision) — the source list IS the
            // final content.
            FridayPackSections::MATERIALS_DELIVERED => (function () use ($project, $start, $end) {
                $items = $this->materialsSources->items($project, ['period_start' => $start, 'period_end' => $end]);

                return ['items' => $items, 'source_site_report_count' => count($items)];
            })(),
            // R1D — Site Issues, Delays & Risks. Confirmed text (null for
            // a pack that doesn't exist yet), deterministic Site Report
            // source count, and optional week-scoped DelayEvent
            // references (never the full DelayEvent history, never
            // ContractRisk).
            FridayPackSections::SITE_ISSUES => [
                'text' => $existingPack?->site_issues_summary,
                'source_site_report_count' => $this->siteIssuesSources->sourceCount(
                    $project, ['period_start' => $start, 'period_end' => $end]
                ),
                'delay_event_references' => $this->siteIssuesSources->delayEventReferences(
                    $project, ['period_start' => $start, 'period_end' => $end]
                ),
            ],
            // R1D — Look Ahead: Next Week's Programme. Confirmed text
            // (null for a pack that doesn't exist yet), a count and small
            // shaped list of milestones falling in the next Monday-Friday
            // window (never the whole Programme).
            FridayPackSections::LOOK_AHEAD => [
                'text' => $existingPack?->look_ahead,
                'source_milestone_count' => $this->lookAheadSources->sourceMilestoneCount($project, $end),
                'milestones' => $this->lookAheadSources->sources($project, $end),
            ],
            // R1E.1 — RAMS. Sourced exclusively from the EXISTING
            // App\Models\DeliveryDocument register (category = 'rams') —
            // never a new RAMS model. See
            // FridayPackComplianceDocumentService for the full
            // "current"/"submitted this week"/"approved this week"
            // contract; no compliance conclusion is ever produced.
            FridayPackSections::RAMS => $this->complianceDocuments->rams(
                $project, ['period_start' => $start, 'period_end' => $end, 'timezone' => $timezone]
            ),
            // R1E.1 — Permits, sourced from DeliveryDocument, unchanged.
            // R1E.2E — Statutory Inspections now populates the previously
            // -empty `inspections` sub-array via
            // FridayPackStatutoryInspectionSourceService, reading the new
            // App\Models\StatutoryInspection register — never
            // DeliveryDocument (a 'temporary_works'/'permit'/'rams'
            // DeliveryDocument is never treated as an inspection event)
            // and never PlantDeployment/PlantItem attachments (presence
            // and evidence are not inspection events either). The
            // `permits`/`permit_source_count` half of this section is
            // completely untouched by this change.
            FridayPackSections::PERMITS_INSPECTIONS => (function () use ($project, $start, $end, $timezone) {
                $permits = $this->complianceDocuments->permits(
                    $project, ['period_start' => $start, 'period_end' => $end, 'timezone' => $timezone]
                );
                $inspections = $this->statutoryInspectionSources->aggregate(
                    $project, ['period_start' => $start, 'period_end' => $end]
                );

                return [
                    'permits'                 => $permits['items'],
                    'inspections'             => $inspections['items'],
                    'permit_source_count'     => $permits['source_count'],
                    'inspection_source_count' => $inspections['source_count'],
                ];
            })(),
            // R1E.2A — Site Inductions. Sourced from the EXISTING
            // App\Models\SiteInduction register (Mon-Fri, soft-deleted
            // rows excluded automatically). ONE ROW = ONE SESSION —
            // never one individual attendee. See
            // FridayPackSiteInductionSourceService for the full
            // total_inducted (summed session attendance, never "unique
            // people") contract.
            FridayPackSections::SITE_INDUCTIONS => $this->siteInductionSources->aggregate(
                $project, ['period_start' => $start, 'period_end' => $end]
            ),
            // R1E.2B — Incidents / Accidents / Near Misses. The first
            // Friday Pack source needing real UTC-vs-local timezone
            // conversion — see FridayPackIncidentSourceService for the
            // full contract. `description` is deliberately never
            // included (data minimisation).
            FridayPackSections::INCIDENTS => $this->incidentSources->aggregate(
                $project, ['period_start' => $start, 'period_end' => $end, 'timezone' => $timezone]
            ),
            // R1E.2C — H&S Inspections. Deliberately never QaReport — see
            // FridayPackHsInspectionSourceService for the full contract.
            // outcome/status frozen exactly as recorded, never collapsed
            // into a compliance conclusion.
            FridayPackSections::HS_INSPECTIONS => $this->hsInspectionSources->aggregate(
                $project, ['period_start' => $start, 'period_end' => $end]
            ),
            // R1E.2D — Plant & Equipment. Authoritative source is
            // PlantDeployment, never PlantItem::status — see
            // FridayPackPlantEquipmentSourceService for the full
            // interval-overlap contract and multi-period preservation
            // rule.
            FridayPackSections::PLANT_EQUIPMENT => $this->plantEquipmentSources->aggregate(
                $project, ['period_start' => $start, 'period_end' => $end]
            ),
            // R1D — Sign Off is deliberately NEVER collected here.
            // Generation always predates review/approval/sending, so
            // freezing lifecycle metadata into snapshot_json at this
            // point would be empty or immediately stale. Sign Off is
            // report LIFECYCLE metadata (FridayPack::$generated_at/by,
            // $reviewed_at/by, $approved_at/by, $sent_at/by — already
            // deliberately excluded from FridayPackIntegrityGuard::
            // PROTECTED_FIELDS since V1B, precisely so it keeps changing)
            // — the frontend/presenter must read it LIVE from the
            // FridayPack model itself, never from this snapshot. This
            // key stays an honest, deliberately-empty stub — an explicit
            // schema-2 lifecycle exception, not an unbuilt collector.
            //
            // R1E.2E gave `permits_inspections`' own `inspections`
            // sub-array (see that section's own match arm above) the
            // last remaining real collector — every authentic
            // FridayPackSections key now has one. This default arm is
            // kept only as a safety net for a genuinely unexpected key
            // (never reached for any key in FridayPackSections::ALL),
            // still an honest empty stub, never fabricated content.
            default => ['count' => 0, 'items' => []],
        };
    }

    /**
     * Deliberately shaped identity payload — never a full model dump, and
     * never anything secret/internal-only (API keys, billing data,
     * internal ids beyond the project's own).
     */
    private function projectIdentity(Project $project): array
    {
        $contract = $project->contracts()->where('type', 'main_contract')->first();

        return [
            'id'                          => $project->id,
            'name'                        => $project->name,
            'code'                        => $project->code,
            'organisation_name'           => $project->organization?->name,
            'currency'                    => $project->resolved_currency,
            'contract_title'              => $contract?->title,
            'contract_form'               => $contract?->form_of_contract,
            'start_date'                  => optional($project->start_date)->toDateString(),
            'practical_completion_date'   => optional($project->practical_completion_date)->toDateString(),
        ];
    }

    // ── Programme ─────────────────────────────────────────────────────────

    private function programme(Project $project, string $start, string $end): array
    {
        $milestones = ContractProgrammeMilestone::where('project_id', $project->id)->get();

        return [
            'count' => $milestones->count(),
            'items' => $milestones->map(fn (ContractProgrammeMilestone $m) => [
                'name'          => $m->name,
                'milestone_type' => $m->milestone_type,
                'planned_date'  => optional($m->planned_date)->toDateString(),
                'forecast_date' => optional($m->forecast_date)->toDateString(),
                'actual_date'   => optional($m->actual_date)->toDateString(),
                'status'        => $m->status,
                'progress_pct'  => $m->progress_pct,
            ])->values()->all(),
        ];
    }

    // ── Toolbox Talks ─────────────────────────────────────────────────────

    private function toolboxTalks(Project $project, string $start, string $end): array
    {
        $talks = ToolboxTalk::where('project_id', $project->id)
            ->whereBetween('talk_date', $this->dateRange($start, $end))
            ->with('deliveredByUser:id,name')
            ->get();

        return [
            'count' => $talks->count(),
            'total_attendee_count' => (int) $talks->sum('attendee_count'),
            'items' => $talks->map(fn (ToolboxTalk $t) => [
                'title'                  => $t->title,
                'talk_date'              => optional($t->talk_date)->toDateString(),
                'delivered_by'           => $t->delivered_by_name ?? $t->deliveredByUser?->name,
                'location'               => $t->location,
                'trade_or_subcontractor' => $t->trade_or_subcontractor,
                'attendee_count'         => $t->attendee_count,
                'status'                 => $t->status,
            ])->values()->all(),
        ];
    }

    // ── Site Reports (SiteDiary) ─────────────────────────────────────────

    private function siteReports(Project $project, string $start, string $end): array
    {
        $diaries = SiteDiary::where('project_id', $project->id)
            ->whereBetween('diary_date', $this->dateRange($start, $end))
            ->get();

        return [
            'count' => $diaries->count(),
            'total_workers_recorded' => (int) $diaries->sum('workers_on_site'),
            'items' => $diaries->map(fn (SiteDiary $d) => [
                'diary_date'          => optional($d->diary_date)->toDateString(),
                'status'              => $d->status,
                'weather'             => $d->weather,
                'workers_on_site'     => $d->workers_on_site,
                'works_carried_out'   => $d->works_carried_out,
                'materials_delivered' => $d->materials_delivered,
                'issues'              => $d->issues,
                'visitors'            => $d->visitors,
            ])->values()->all(),
        ];
    }

    // ── Risks ─────────────────────────────────────────────────────────────

    private function risks(Project $project, string $start, string $end): array
    {
        $risks = ContractRisk::where('project_id', $project->id)
            ->whereIn('status', ['open', 'in_progress'])
            ->get();

        return [
            'count' => $risks->count(),
            'items' => $risks->map(fn (ContractRisk $r) => [
                'title'       => $r->title,
                'severity'    => $r->severity,
                'probability' => $r->probability,
                'category'    => $r->category,
                'status'      => $r->status,
                'review_date' => optional($r->review_date)->toDateString(),
            ])->values()->all(),
        ];
    }

    // ── RFIs ──────────────────────────────────────────────────────────────

    private function rfis(Project $project, string $start, string $end): array
    {
        $raised    = Rfi::where('project_id', $project->id)->whereBetween('raised_date', $this->dateRange($start, $end))->get();
        $responded = Rfi::where('project_id', $project->id)->whereBetween('responded_at', $this->dateRange($start, $end))->get();
        $today     = Carbon::parse($end)->addDay()->toDateString();
        $outstanding = Rfi::where('project_id', $project->id)
            ->whereNotIn('status', ['responded', 'closed'])
            ->get();
        $overdue = $outstanding->filter(fn (Rfi $r) => $r->response_due_date && $r->response_due_date->lt(Carbon::parse($today)));

        return [
            'raised_count'      => $raised->count(),
            'responded_count'   => $responded->count(),
            'outstanding_count' => $outstanding->count(),
            'overdue_count'     => $overdue->count(),
            'items' => $outstanding->map(fn (Rfi $r) => [
                'subject'            => $r->subject,
                'priority'           => $r->priority,
                'status'             => $r->status,
                'raised_date'        => optional($r->raised_date)->toDateString(),
                'response_due_date'  => optional($r->response_due_date)->toDateString(),
            ])->values()->all(),
        ];
    }

    // ── Variations ────────────────────────────────────────────────────────

    private function variations(Project $project, string $start, string $end): array
    {
        $variations = Variation::where('project_id', $project->id)
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('variation_date', $this->dateRange($start, $end))
                    ->orWhereIn('status', Variation::IN_PROGRESS_STATUSES);
            })
            ->get();

        return [
            'count' => $variations->count(),
            'items' => $variations->map(fn (Variation $v) => [
                'title'          => $v->title,
                'type'           => $v->type,
                'status'         => $v->status,
                'variation_date' => optional($v->variation_date)->toDateString(),
                'quoted_amount'  => $v->quoted_amount !== null ? (float) $v->quoted_amount : null,
                'agreed_amount'  => $v->agreed_amount !== null ? (float) $v->agreed_amount : null,
                'submitted_at'   => optional($v->submitted_at)->toDateString(),
                'approved_at'    => optional($v->approved_at)->toDateString(),
            ])->values()->all(),
        ];
    }

    // ── Commercial (CommercialAggregationService — mandatory reuse) ──────

    private function commercial(Project $project, string $start, string $end): array
    {
        $ids  = collect([$project->id]);
        $from = Carbon::parse($start);
        $to   = Carbon::parse($end);

        $paTotals          = $this->commercial->paymentApplicationTotalsByProject($ids, $from, $to);
        $retentionReleased = $this->commercial->retentionReleasedByProject($ids, $from, $to);
        $contractValues    = $this->commercial->contractValueByProject($ids);
        $variationTotals   = $this->commercial->variationTotalsByProject($ids, $from, $to);
        $pipeline          = $this->commercial->paymentApplicationPipelineByProject($ids, $from, $to);

        $certified = (float) ($paTotals[$project->id]->certified ?? 0);
        $paid      = (float) ($paTotals[$project->id]->paid ?? 0);
        $retention = $this->commercial->retentionHeld(
            (float) ($paTotals[$project->id]->retention_withheld ?? 0),
            (float) ($retentionReleased[$project->id]->released ?? 0),
        );

        return [
            'currency'                  => $project->resolved_currency,
            'contract_value'            => (float) ($contractValues[$project->id]->value ?? 0),
            'certified_this_period'     => $certified,
            'paid_this_period'          => $paid,
            'outstanding'               => $certified - $paid,
            'retention_held'            => $retention,
            'approved_variation_value'  => (float) ($variationTotals[$project->id]->approved_value ?? 0),
            'pending_variation_value'   => (float) ($variationTotals[$project->id]->pending_value ?? 0),
            'awaiting_certification_count' => (int) ($pipeline[$project->id]->awaiting_certification_count ?? 0),
            'certified_unpaid_count'    => (int) ($pipeline[$project->id]->certified_unpaid_count ?? 0),
        ];
    }

    // ── Delays / EOT ──────────────────────────────────────────────────────

    private function delaysEot(Project $project, string $start, string $end): array
    {
        $delays = DelayEvent::where('project_id', $project->id)
            ->whereBetween('date_occurred', $this->dateRange($start, $end))
            ->get();
        $eots = EotRequest::where('project_id', $project->id)
            ->whereBetween('notice_date', $this->dateRange($start, $end))
            ->get();

        return [
            'delay_events' => [
                'count' => $delays->count(),
                'items' => $delays->map(fn (DelayEvent $d) => [
                    'title'                 => $d->title,
                    'cause_category'        => $d->cause_category,
                    'date_occurred'         => optional($d->date_occurred)->toDateString(),
                    'estimated_delay_days'  => $d->estimated_delay_days,
                    'status'                => $d->status,
                ])->values()->all(),
            ],
            'eot_requests' => [
                'count' => $eots->count(),
                'items' => $eots->map(fn (EotRequest $e) => [
                    'title'                    => $e->title,
                    'notice_date'              => optional($e->notice_date)->toDateString(),
                    'days_claimed'             => $e->days_claimed,
                    'days_granted'             => $e->days_granted,
                    'status'                   => $e->status,
                    'revised_completion_date'  => optional($e->revised_completion_date)->toDateString(),
                ])->values()->all(),
            ],
        ];
    }

    // ── Meetings / Actions ────────────────────────────────────────────────

    private function meetingsActions(Project $project, string $start, string $end): array
    {
        $meetings = MeetingMinutes::where('project_id', $project->id)
            ->whereBetween('meeting_date', $this->dateRange($start, $end))
            ->get();

        $outstandingActions = [];
        foreach ($meetings as $meeting) {
            foreach ((array) $meeting->action_items as $item) {
                $outstandingActions[] = is_array($item) ? $item : ['description' => (string) $item];
            }
        }

        return [
            'count' => $meetings->count(),
            'items' => $meetings->map(fn (MeetingMinutes $m) => [
                'title'        => $m->title,
                'type'         => $m->type,
                'meeting_date' => optional($m->meeting_date)->toDateString(),
                'status'       => $m->status,
                'action_items' => $m->action_items ?? [],
            ])->values()->all(),
            'action_item_count' => count($outstandingActions),
        ];
    }

    // ── Delivery Documents ────────────────────────────────────────────────

    private function deliveryDocuments(Project $project, string $start, string $end): array
    {
        $documents = DeliveryDocument::where('project_id', $project->id)
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('submitted_at', $this->dateRange($start, $end))
                    ->orWhereBetween('reviewed_at', $this->dateRange($start, $end))
                    ->orWhereBetween('approved_at', $this->dateRange($start, $end))
                    ->orWhereBetween('due_date', $this->dateRange($start, $end))
                    ->orWhereNotIn('status', ['approved', 'rejected', 'expired', 'superseded']);
            })
            ->get();

        return [
            'count' => $documents->count(),
            'items' => $documents->map(fn (DeliveryDocument $d) => [
                'title'    => $d->title,
                'category' => $d->category,
                'status'   => $d->status,
                'due_date' => optional($d->due_date)->toDateString(),
            ])->values()->all(),
        ];
    }

    // ── Drawings ──────────────────────────────────────────────────────────

    private function drawings(Project $project, string $start, string $end): array
    {
        $revisions = DrawingRevision::whereHas('drawing', fn ($q) => $q->where('project_id', $project->id))
            ->whereBetween('issued_date', $this->dateRange($start, $end))
            ->with('drawing:id,drawing_number,title')
            ->get();

        return [
            'count' => $revisions->count(),
            'items' => $revisions->map(fn (DrawingRevision $r) => [
                'drawing_number' => $r->drawing?->drawing_number,
                'drawing_title'  => $r->drawing?->title,
                'revision_code'  => $r->revision_code,
                'status'         => $r->status,
                'issued_date'    => optional($r->issued_date)->toDateString(),
            ])->values()->all(),
        ];
    }

    // ── QA / Snagging ─────────────────────────────────────────────────────

    private function qaSnagging(Project $project, string $start, string $end): array
    {
        $qaReports = QaReport::where('project_id', $project->id)
            ->whereBetween('inspection_date', $this->dateRange($start, $end))
            ->get();

        $snagsCreated = Snag::where('project_id', $project->id)
            ->whereBetween('created_at', $this->dateRange($start, $end))
            ->get();
        $snagsClosed = Snag::where('project_id', $project->id)
            ->whereBetween('closed_at', $this->dateRange($start, $end))
            ->get();
        $snagsOutstanding = Snag::where('project_id', $project->id)
            ->whereNotIn('status', ['closed'])
            ->get();

        return [
            'qa' => [
                'count' => $qaReports->count(),
                'items' => $qaReports->map(fn (QaReport $q) => [
                    'title'  => $q->title,
                    'result' => $q->result,
                    'status' => $q->status,
                    'inspection_date' => optional($q->inspection_date)->toDateString(),
                ])->values()->all(),
            ],
            'snagging' => [
                'created_count'     => $snagsCreated->count(),
                'closed_count'      => $snagsClosed->count(),
                'outstanding_count' => $snagsOutstanding->count(),
                'items' => $snagsOutstanding->map(fn (Snag $s) => [
                    'title'    => $s->title,
                    'priority' => $s->priority,
                    'status'   => $s->status,
                    'due_date' => optional($s->due_date)->toDateString(),
                ])->values()->all(),
            ],
        ];
    }

    // ── Upcoming Actions (UpcomingActionsService — mandatory reuse) ──────

    private function upcomingActionsSection(Project $project): array
    {
        // UpcomingActionsService::getActionsForProject() is already
        // project-scoped (App\Services\OperationalIntelligenceService's own
        // getItemsForProject(int $projectId, ...) underneath it) — this
        // calls it directly rather than reproducing its normalization or
        // overdue/upcoming classification logic.
        $actions = $this->upcomingActions->getActionsForProject($project->id);

        return [
            'count' => count($actions),
            'items' => array_map(fn (array $a) => [
                'title'          => $a['title'],
                'category'       => $a['category'],
                'priority'       => $a['priority'],
                'due_date'       => $a['due_date'],
                'days_remaining' => $a['days_remaining'],
                'status'         => $a['status'],
            ], $actions),
        ];
    }
}
