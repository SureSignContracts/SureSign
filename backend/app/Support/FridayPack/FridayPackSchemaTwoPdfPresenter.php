<?php

namespace App\Support\FridayPack;

use App\Models\FileUpload;
use App\Models\FridayPack;
use App\Services\BrandingService;
use App\Services\FridayPack\FridayPackPhotoPdfOptimisationService;
use App\Services\FridayPack\FridayPackReadinessService;
use Carbon\Carbon;

/**
 * Friday Pack Realignment, R1G.1 — the ONE presentation layer for the
 * schema-2 (authentic construction) Friday Pack PDF. Mirrors
 * `FridayPackPdfPresenter`'s existing role for schema-1 exactly — reads
 * only already-persisted/derivable state, never mutates anything, never
 * calls the AI provider or any live business aggregation service. Schema-1
 * and this class are deliberately SEPARATE, unrelated presenters (see
 * `FridayPackPdfService`'s own docblock) — this class exists purely so
 * schema-1's own presenter/template stay completely untouched.
 *
 * Reads from THREE sources, matching R1F.2's own architecture exactly —
 * never re-deriving any of this itself:
 *   1. the pack's frozen `snapshot_json.sections.*` for every SOURCE-typed
 *      section (real rows, never re-queried live);
 *   2. the pack's LIVE `weekly_summary`/`site_issues_summary`/`look_ahead`
 *      columns for every TEXT-typed section (editable independently of
 *      regeneration — see `FridayPackReadinessService`'s own docblock for
 *      why the frozen snapshot copy must never be used for these);
 *   3. `FridayPackReadinessService::evaluate()` for the render-mode
 *      decision itself (source present / effective declaration / missing)
 *      — this class NEVER re-implements `FridayPackReadinessMatrix`'s own
 *      rules; it only asks the existing readiness evaluator "is this
 *      leaf's state source/declared/missing" and, when declared, reads the
 *      exact approved statement text the evaluator already resolved via
 *      `FridayPackReadinessMatrix::statementFor()`. One authoritative
 *      source of truth for declaration policy, never a second copy in the
 *      PDF layer.
 *
 * `Sign Off` is the one section deliberately read entirely LIVE from the
 * `FridayPack` model's own lifecycle columns (`generated_at/by`,
 * `reviewed_at/by`, `approved_at/by`, `sent_at/by`) — it is never in the
 * snapshot and never goes through the readiness evaluator (R1F.2's own
 * `TYPE_EXCLUDED` — see `FridayPackReadinessMatrix`).
 *
 * **R1G.1-VFIX2 — presentation hierarchy restructure (2026-09-02).**
 * Superseding R1G.1's own flat, sequentially-numbered 15-section list:
 * external visual QA against the boss-provided construction reference
 * found the flat list read as a generic modern report, not a real
 * site-issued Friday Pack. This is a PRESENTATION-ONLY change — every
 * backend key (`FridayPackSections`), readiness rule
 * (`FridayPackReadinessMatrix`), and declaration/source-precedence
 * mechanism is completely unchanged; only how the 15 keys are GROUPED and
 * NUMBERED for display changes. `Report Information` is no longer a
 * numbered section at all (presented as a document header/control block,
 * matching the reference's own convention). The remaining 14 keys collapse
 * into a FIXED 10-item presentation hierarchy — `rams`/`toolbox_talks`/
 * `site_inductions`/`incidents`/`hs_inspections` group under one parent,
 * "4. Health & Safety", as subsections 4.1–4.5:
 *
 *   1  weekly_summary
 *   2  workforce
 *   3  site_photographs
 *   4  (Health & Safety — parent heading only, no content of its own)
 *      4.1 rams · 4.2 toolbox_talks · 4.3 site_inductions ·
 *      4.4 incidents · 4.5 hs_inspections
 *   5  permits_inspections (two independent subsections, unchanged)
 *   6  plant_equipment
 *   7  materials_delivered
 *   8  site_issues
 *   9  look_ahead
 *   10 sign_off
 *
 * Numbering is FIXED (never dynamically renumbered to close a gap left by
 * an excluded section) — a deliberate, explicit change from R1G.1's own
 * "sequential, no gaps" decision, scoped ONLY to this hierarchy: a real
 * paper form's section numbers don't shift because one chapter doesn't
 * apply this week (see the boss reference's own "5. PERMITS TO WORK...
 * NO PERMITS REQUIRED" — the section number stays, only its content
 * changes). If a top-level entry has no included backend key at all, it
 * is omitted entirely (no heading with nothing under it); "4. Health &
 * Safety" itself is omitted only if ALL FIVE of its children are excluded.
 *
 * Photo preparation (`FridayPackPhotoPdfOptimisationService::prepare()`)
 * happens HERE, eagerly, while shaping presentation data — never inside
 * the Blade template (this is business/rendering policy, not markup).
 * Every temp file path produced is collected into `$tempFiles` and
 * returned alongside the presented data so `FridayPackPdfService::generate()`
 * can delete them in a `finally` block regardless of whether the overall
 * PDF generation succeeds.
 */
class FridayPackSchemaTwoPdfPresenter
{
    /** Presentation labels — matches the R1G.1-VFIX2 construction-document hierarchy, not `FridayPackReadinessMatrix`'s own generic labels. */
    private const SECTION_LABELS = [
        FridayPackSections::WEEKLY_SUMMARY       => 'Weekly Summary',
        FridayPackSections::WORKFORCE            => 'Workforce on Site',
        FridayPackSections::SITE_PHOTOGRAPHS     => 'Site Photographs',
        FridayPackSections::RAMS                 => 'RAMS in Use This Week',
        FridayPackSections::TOOLBOX_TALKS        => 'Toolbox Talks / Briefings',
        FridayPackSections::SITE_INDUCTIONS      => 'Site Inductions',
        FridayPackSections::INCIDENTS            => 'Accidents / Incidents / Near Misses',
        FridayPackSections::HS_INSPECTIONS       => 'H&S Inspections',
        FridayPackSections::PERMITS_INSPECTIONS  => 'Permits to Work & Statutory Inspections',
        FridayPackSections::PLANT_EQUIPMENT      => 'Plant & Equipment on Site',
        FridayPackSections::MATERIALS_DELIVERED  => 'Materials Delivered This Week',
        FridayPackSections::SITE_ISSUES          => 'Site Issues, Delays & Risks',
        FridayPackSections::LOOK_AHEAD           => 'Look Ahead — Next Week\'s Programme',
        FridayPackSections::SIGN_OFF             => 'Sign Off',
    ];

    private const HEALTH_AND_SAFETY_LABEL = 'Health & Safety';

    /** The five backend keys grouped under presentation section 4. */
    private const HEALTH_AND_SAFETY_CHILDREN = [
        FridayPackSections::RAMS,
        FridayPackSections::TOOLBOX_TALKS,
        FridayPackSections::SITE_INDUCTIONS,
        FridayPackSections::INCIDENTS,
        FridayPackSections::HS_INSPECTIONS,
    ];

    /** Restrained, non-factual helper text — never a claim, matching the reference's own italic instructional lines. */
    private const HELPER_TEXT = [
        FridayPackSections::WORKFORCE => 'Operatives on site during the reporting period, by trade/role.',
        FridayPackSections::SITE_PHOTOGRAPHS => 'Photographic evidence of progress, workmanship and site conditions.',
        FridayPackSections::SITE_ISSUES => 'Access, design, delivery or resource issues affecting the reporting period.',
    ];

    public function __construct(
        private FridayPackReadinessService $readiness,
        private FridayPackPhotoPdfOptimisationService $photoOptimiser,
    ) {}

    /**
     * @return array{data: array, temp_files: string[]}
     */
    public function present(FridayPack $pack): array
    {
        $project = $pack->project;
        $snapshot = $pack->snapshot_json ?? [];
        $sections = $snapshot['sections'] ?? [];
        $includedSections = $pack->settings_snapshot_json['included_sections'] ?? FridayPackSections::defaults();

        $readinessResult = $this->readiness->evaluate($pack);
        $sectionStates = $readinessResult['sections'] ?? [];

        $tempFiles = [];

        $isIncluded = fn (string $key) => in_array($key, $includedSections, true) && array_key_exists($key, $sections);

        // Fixed top-level presentation order (R1G.1-VFIX2) — never
        // dynamically renumbered; see class docblock.
        $renderedSections = [];

        foreach ([
            ['1', FridayPackSections::WEEKLY_SUMMARY],
            ['2', FridayPackSections::WORKFORCE],
            ['3', FridayPackSections::SITE_PHOTOGRAPHS],
        ] as [$num, $key]) {
            if ($isIncluded($key)) {
                $renderedSections[] = $this->buildStandardSection($num, $key, $sections[$key], $sectionStates[$key] ?? null, $pack, $tempFiles);
            }
        }

        // "4. Health & Safety" — a parent heading only, no content of its
        // own; omitted entirely if none of its five children are included.
        $hsChildren = [];
        $hsSubNumbers = ['4.1', '4.2', '4.3', '4.4', '4.5'];
        foreach (self::HEALTH_AND_SAFETY_CHILDREN as $i => $key) {
            if ($isIncluded($key)) {
                $hsChildren[] = $this->buildStandardSection($hsSubNumbers[$i], $key, $sections[$key], $sectionStates[$key] ?? null, $pack, $tempFiles);
            }
        }
        if (!empty($hsChildren)) {
            $renderedSections[] = [
                'key' => 'health_and_safety',
                'number' => '4',
                'label' => self::HEALTH_AND_SAFETY_LABEL,
                'kind' => 'health_and_safety',
                'children' => $hsChildren,
            ];
        }

        if ($isIncluded(FridayPackSections::PERMITS_INSPECTIONS)) {
            $sectionData = $sections[FridayPackSections::PERMITS_INSPECTIONS];
            $leafState = $sectionStates[FridayPackSections::PERMITS_INSPECTIONS] ?? null;
            $renderedSections[] = [
                'key' => FridayPackSections::PERMITS_INSPECTIONS,
                'number' => '5',
                'label' => self::SECTION_LABELS[FridayPackSections::PERMITS_INSPECTIONS],
                'kind' => 'permits_inspections',
                'permits' => $this->permits($sectionData, $leafState['subsections']['permits'] ?? null),
                'inspections' => $this->statutoryInspections($sectionData, $leafState['subsections']['inspections'] ?? null),
            ];
        }

        foreach ([
            ['6', FridayPackSections::PLANT_EQUIPMENT],
            ['7', FridayPackSections::MATERIALS_DELIVERED],
            ['8', FridayPackSections::SITE_ISSUES],
            ['9', FridayPackSections::LOOK_AHEAD],
        ] as [$num, $key]) {
            if ($isIncluded($key)) {
                $renderedSections[] = $this->buildStandardSection($num, $key, $sections[$key], $sectionStates[$key] ?? null, $pack, $tempFiles);
            }
        }

        if (in_array(FridayPackSections::SIGN_OFF, $includedSections, true)) {
            $renderedSections[] = [
                'key'    => FridayPackSections::SIGN_OFF,
                'number' => '10',
                'label'  => self::SECTION_LABELS[FridayPackSections::SIGN_OFF],
                'kind'   => 'sign_off',
                'sign_off' => $this->signOff($pack),
            ];
        }

        $branding = BrandingService::forOrganization($pack->organization_id);

        // Report Information is a document header/control block, not a
        // numbered section (R1G.1-VFIX2) — still respects exclusion via
        // included_sections (a project can technically disable it, though
        // it's CORE-recommended and this is not yet enforced — see
        // FridayPackSections's own docblock).
        $reportInformation = $isIncluded(FridayPackSections::REPORT_INFORMATION)
            ? $this->reportInformation(
                $sections[FridayPackSections::REPORT_INFORMATION] ?? [],
                $sectionStates[FridayPackSections::REPORT_INFORMATION] ?? null,
                $pack,
            )
            : null;

        $data = [
            'schema_version'            => 2,
            'status'                    => $pack->status,
            'is_draft'                  => $pack->status === 'draft',
            'status_label'              => $this->statusLabel($pack->status),
            'title'                     => "Friday Pack — {$project->name} — Week Ending " . $this->formatLongDate($pack->week_ending),
            'report_number'             => $pack->report_number,
            'organisation_display_name' => BrandingService::displayName($branding, $project->organization),
            'week_ending_label'         => $this->formatLongDate($pack->week_ending),
            'period_label'              => $this->formatLongDate($pack->period_start) . ' to ' . $this->formatLongDate($pack->period_end),
            'report_information'        => $reportInformation,
            'sections'                  => $renderedSections,
        ];

        return ['data' => $data, 'temp_files' => $tempFiles];
    }

    // ── Per-section content shaping ─────────────────────────────────────

    private function buildStandardSection(string $number, string $key, array $sectionData, ?array $leafState, FridayPack $pack, array &$tempFiles): array
    {
        $render = $this->renderMode($leafState);

        return [
            'key' => $key,
            'number' => $number,
            'label' => self::SECTION_LABELS[$key] ?? ucfirst(str_replace('_', ' ', $key)),
            'helper_text' => self::HELPER_TEXT[$key] ?? null,
            'kind' => 'standard',
            'render_mode' => $render['mode'],
            'declaration' => $render['declaration'] ?? null,
            'content' => $this->shapeContent($key, $sectionData, $pack, $tempFiles),
        ];
    }

    /**
     * The one place a leaf's readiness `state` is translated into a PDF
     * render decision — never a second implementation of
     * `FridayPackReadinessMatrix`'s own rules. `$leafState` is exactly
     * what `FridayPackReadinessService::evaluate()` already produced for
     * this (section, subsection) pair.
     *
     * @return array{mode: string, declaration?: ?array}
     */
    private function renderMode(?array $leafState): array
    {
        $state = $leafState['state'] ?? 'missing';

        return match ($state) {
            'complete' => ['mode' => 'source'],
            'confirmed_none', 'not_applicable' => ['mode' => 'declaration', 'declaration' => $this->shapeDeclaration($leafState['declaration'] ?? null)],
            default => ['mode' => 'missing'],
        };
    }

    /** Never renders "Confirmed by —"/"Unknown user" — omits the attribution line entirely when the confirming user has been deleted. */
    private function shapeDeclaration(?array $declaration): ?array
    {
        if ($declaration === null) {
            return null;
        }

        $confirmedBy = $declaration['confirmed_by']['name'] ?? null;
        $confirmedAt = $declaration['confirmed_at'] ?? null;

        return [
            'statement' => $declaration['statement'],
            'note' => $declaration['note'] ?? null,
            'attribution' => ($confirmedBy && $confirmedAt)
                ? "Confirmed by {$confirmedBy} · " . $this->formatLongDate(Carbon::parse($confirmedAt))
                : null,
        ];
    }

    private function shapeContent(string $key, array $data, FridayPack $pack, array &$tempFiles): array
    {
        return match ($key) {
            FridayPackSections::WEEKLY_SUMMARY => ['text' => $pack->weekly_summary],
            FridayPackSections::SITE_ISSUES => ['text' => $pack->site_issues_summary],
            FridayPackSections::LOOK_AHEAD => [
                'text' => $pack->look_ahead,
                'milestones' => array_map(fn (array $m) => [
                    'name'          => $m['name'],
                    'milestone_type'=> $this->humanize($m['milestone_type'] ?? null),
                    'upcoming_date' => $this->formatDate($m['upcoming_date'] ?? null),
                ], $data['milestones'] ?? []),
            ],
            FridayPackSections::WORKFORCE => $this->workforce($data),
            FridayPackSections::SITE_PHOTOGRAPHS => $this->photographs($data, $pack, $tempFiles),
            FridayPackSections::RAMS => $this->complianceDocuments($data['items'] ?? []),
            FridayPackSections::TOOLBOX_TALKS => $this->toolboxTalks($data['items'] ?? []),
            FridayPackSections::SITE_INDUCTIONS => $this->siteInductions($data),
            FridayPackSections::INCIDENTS => $this->incidents($data['items'] ?? []),
            FridayPackSections::HS_INSPECTIONS => $this->hsInspections($data['items'] ?? []),
            FridayPackSections::PLANT_EQUIPMENT => $this->plantEquipment($data['items'] ?? []),
            FridayPackSections::MATERIALS_DELIVERED => $this->materials($data['items'] ?? []),
            default => [],
        };
    }

    private function reportInformation(array $info, ?array $leafState, FridayPack $pack): array
    {
        $siteAddress = array_filter([
            $info['site_address']['address'] ?? null,
            $info['site_address']['city'] ?? null,
            $info['site_address']['state'] ?? null,
            $info['site_address']['postcode'] ?? null,
            $info['site_address']['country'] ?? null,
        ]);

        $deliveries = $pack->deliveries;
        $distributedTo = $deliveries->isNotEmpty()
            ? $deliveries->pluck('recipient_name')->filter()->implode(', ')
            : null;

        return [
            'project_name'           => $info['project_name'] ?? $pack->project->name,
            'site_address'           => $siteAddress ? implode(', ', $siteAddress) : null,
            'principal_contractor'   => $info['principal_contractor'] ?? null,
            'reporting_organisation' => $info['reporting_organisation_name'] ?? null,
            'sub_contract_order_no'  => $info['sub_contract_order_no'] ?? null,
            'scope_of_works'         => $info['scope_of_works'] ?? null,
            'report_number'          => $info['report_number'] ?? $pack->report_number,
            'week_commencing'        => $this->formatLongDate($info['week_commencing'] ?? $pack->period_start),
            'week_ending'            => $this->formatLongDate($info['week_ending'] ?? $pack->week_ending),
            'prepared_by'            => $info['prepared_by'] ?? null,
            'date_issued'            => $pack->sent_at ? $this->formatLongDate($pack->sent_at) : 'Not issued',
            'distributed_to'         => $distributedTo,
        ];
    }

    /**
     * R1G.1-VFIX3 — ONE primary construction workforce table (superseding
     * VFIX2's separate weekday-totals band + trade table, which read as
     * two stitched-together application widgets). When a trade breakdown
     * exists, rows are the trades plus a final "Daily Total" row built
     * from the SAME `days[].daily_total` figures the old summary band
     * showed — never a second, independently-derived total. When no
     * trade breakdown exists, the Daily Total row is the table's only
     * data row — still one table, never two.
     */
    private function workforce(array $data): array
    {
        $days = array_map(fn (array $d) => [
            'day'         => $d['day'],
            'date'        => $this->formatDate($d['date']),
            'daily_total' => $d['status'] === 'conflicting_site_reports' ? 'Conflicting reports' : ($d['daily_total'] ?? '—'),
        ], $data['days'] ?? []);

        return [
            'days' => $days,
            'rows' => $data['rows'] ?? [],
            'has_trade_breakdown' => $data['has_trade_breakdown'] ?? false,
            'person_days_total' => $data['person_days_total'] ?? 0,
            'has_conflicts' => $data['has_conflicts'] ?? false,
        ];
    }

    /**
     * R1G.1-VFIX5 — controlled TWO-COLUMN evidence system, superseding
     * VFIX3's own "compact vs landscape" grouping. External QA on VFIX3's
     * layout found the result "visually disorganised" and wasteful of
     * page space: a normal landscape photo rendered at near-full content
     * width consumed almost an entire page by itself (its height follows
     * proportionally from that width), so a page could carry only one
     * landscape photo despite substantial unused space remaining. The fix
     * is NOT a page-budget height calculation (Dompdf paginates ordinary
     * overflowing block content automatically, exactly like a browser
     * print — the actual defect was that individual photos were sized too
     * large, not that pagination itself was broken) — it's sizing every
     * ORDINARY (non-panoramic) photo, regardless of orientation, to fit
     * ONE evidence column (roughly half the content width), so two
     * naturally pair per row and Dompdf's own automatic flow fills pages
     * efficiently without any manual height math.
     *
     * Photos are paired SEQUENTIALLY in selection order — never grouped
     * by orientation first — so 2 landscape + 2 portrait photos (in that
     * order) become [landscape, landscape] then [portrait, portrait],
     * matching the natural expectation of the order they were selected
     * in, not a reshuffled "put all portraits together" scheme. Only a
     * genuinely PANORAMIC photo (aspect ratio >= 2.0 — a real threshold,
     * not the earlier 1.8 "landscape" cutoff, which classified ordinary
     * 16:9-ish photos as "very_wide" and let them wrongly claim full-width
     * treatment) breaks pairing and spans the full evidence width; every
     * other orientation (landscape/portrait/near_square/very_tall) is an
     * ordinary column candidate. Never both a fixed width AND a fixed
     * height on any `<img>` — see `_photographs.blade.php`'s own CSS,
     * `max-width`/`max-height` with `height: auto` only, so the real
     * aspect ratio is always preserved regardless of which column a photo
     * lands in.
     *
     * Orientation is derived once, presentation-only, from the OPTIMISED
     * rendition's real pixel dimensions (`getimagesize()` on the file
     * `FridayPackPhotoPdfOptimisationService::prepare()` already
     * produced — no second decode) — never stored, never written to
     * `FileUpload`.
     *
     * @param  string[]  $tempFiles
     */
    private function photographs(array $data, FridayPack $pack, array &$tempFiles): array
    {
        $items = $data['items'] ?? [];
        if (empty($items)) {
            return ['rows' => []];
        }

        $uploadIds = array_column($items, 'file_upload_id');
        $uploads = FileUpload::whereIn('id', $uploadIds)->get()->keyBy('id');

        $shaped = [];
        foreach ($items as $item) {
            $upload = $uploads->get($item['file_upload_id']);
            $imageUri = null;
            $orientation = 'landscape'; // safe default for a placeholder (never rendered with dimension-dependent CSS anyway)

            if ($upload) {
                $tempPath = $this->photoOptimiser->prepare($upload);
                if ($tempPath) {
                    $tempFiles[] = $tempPath;
                    $imageUri = 'file://' . $tempPath;
                    $dimensions = @getimagesize($tempPath);
                    if ($dimensions && $dimensions[0] > 0 && $dimensions[1] > 0) {
                        $orientation = $this->classifyOrientation($dimensions[0], $dimensions[1]);
                    }
                }
            }

            $captionLocation = array_filter([$item['caption'] ?? null, $item['location'] ?? null]);

            $shaped[] = [
                'image_uri'        => $imageUri,
                'orientation'      => $orientation,
                'is_panoramic'     => $orientation === 'panoramic',
                'caption_location' => $captionLocation ? implode(' — ', $captionLocation) : null,
            ];
        }

        return ['rows' => $this->groupPhotosIntoRows($shaped)];
    }

    private function classifyOrientation(int $width, int $height): string
    {
        $ratio = $width / $height;

        return match (true) {
            $ratio >= 2.0 => 'panoramic',
            $ratio > 1.15 => 'landscape',
            $ratio >= 0.87 => 'near_square',
            $ratio > 0.56 => 'portrait',
            default => 'very_tall',
        };
    }

    /**
     * Sequential pairing — see this method's own caller docblock for why
     * this is deliberately NOT orientation-grouped. Only a `panoramic`
     * photo breaks pairing (always its own full-width row); every other
     * orientation is an ordinary evidence-column candidate, paired two to
     * a row in the order photos were selected. A trailing unpaired photo
     * (odd total count) renders alone, at the SAME single-column width as
     * a paired photo — never stretched to fill the row, and never treated
     * as if it were panoramic.
     *
     * @return array<int, array{kind: string, photos: array}>
     */
    private function groupPhotosIntoRows(array $photos): array
    {
        // Exactly one photo overall — render it larger (its own row,
        // never squeezed into a half-width evidence column) rather than
        // the trailing-single treatment used for an odd leftover among
        // several photos. A panoramic lone photo already gets this same
        // full-width treatment via the 'panoramic' kind below.
        if (count($photos) === 1 && !$photos[0]['is_panoramic']) {
            return [['kind' => 'lone', 'photos' => [$photos[0]]]];
        }

        $rows = [];
        $pending = null;

        foreach ($photos as $photo) {
            if ($photo['is_panoramic']) {
                if ($pending) {
                    $rows[] = ['kind' => 'single', 'photos' => [$pending]];
                    $pending = null;
                }
                $rows[] = ['kind' => 'panoramic', 'photos' => [$photo]];
                continue;
            }

            if ($pending) {
                $rows[] = ['kind' => 'pair', 'photos' => [$pending, $photo]];
                $pending = null;
            } else {
                $pending = $photo;
            }
        }

        if ($pending) {
            $rows[] = ['kind' => 'single', 'photos' => [$pending]];
        }

        return $rows;
    }

    private function complianceDocuments(array $items): array
    {
        return array_map(fn (array $d) => [
            'title'    => $d['title'],
            'revision' => $d['revision'] ?? '—',
            'status'   => $this->humanize($d['status']),
            'current'  => $d['current'] ? 'Yes' : '—',
            'submitted_this_week' => $d['submitted_this_week'] ? 'Yes' : '—',
            'approved_this_week'  => $d['approved_this_week'] ? 'Yes' : '—',
            'expiry_date' => $this->formatDate($d['expiry_date'] ?? null),
        ], $items);
    }

    private function toolboxTalks(array $items): array
    {
        return array_map(fn (array $t) => [
            'talk_date' => $this->formatDate($t['talk_date'] ?? null),
            'title' => $t['title'],
            'trade_or_subcontractor' => $t['trade_or_subcontractor'] ?? '—',
            'attendee_count' => $t['attendee_count'] ?? 0,
        ], $items);
    }

    private function siteInductions(array $data): array
    {
        return [
            'items' => array_map(fn (array $s) => [
                'date' => $this->formatDate($s['date'] ?? null),
                'session_title' => $s['session_title'] ?? '—',
                'company_or_trade' => $s['company_or_trade'] ?? '—',
                'inductee_count' => $s['inductee_count'],
            ], $data['items'] ?? []),
            'total_inducted' => $data['total_inducted'] ?? 0,
        ];
    }

    private function incidents(array $items): array
    {
        return array_map(fn (array $i) => [
            'date' => $this->formatDate($i['local_date'] ?? null),
            'time' => $i['local_time'] ?? '—',
            'type' => $this->humanize($i['type']),
            'title' => $i['title'],
            'location' => $i['location'] ?? '—',
            // Tri-state preserved exactly — never coerced.
            'injury' => match ($i['injury_occurred']) {
                true => 'Injury occurred',
                false => 'No injury',
                default => 'Not confirmed',
            },
            'reportability' => $this->humanize($i['regulatory_reportability']),
            'status' => $this->humanize($i['status']),
        ], $items);
    }

    private function hsInspections(array $items): array
    {
        return array_map(fn (array $i) => [
            'date' => $this->formatDate($i['date'] ?? null),
            'inspection_type' => $i['inspection_type'],
            'inspected_by' => $i['inspected_by'],
            'outcome' => $this->humanize($i['outcome']),
            'status' => $this->humanize($i['status']),
            'findings' => $i['findings'] ?? null,
            'actions' => $i['actions'] ?? null,
        ], $items);
    }

    private function permits(array $sectionData, ?array $leafState): array
    {
        $render = $this->renderMode($leafState);
        $items = $sectionData['permits'] ?? [];

        return [
            'label' => 'Permits',
            'render_mode' => $render['mode'],
            'declaration' => $render['declaration'] ?? null,
            'items' => array_map(fn (array $p) => [
                'title'    => $p['title'],
                'revision' => $p['revision'] ?? '—',
                'status'   => $this->humanize($p['status']),
                'current'  => $p['current'] ? 'Yes' : '—',
                'expiry_date' => $this->formatDate($p['expiry_date'] ?? null),
            ], $items),
        ];
    }

    private function statutoryInspections(array $sectionData, ?array $leafState): array
    {
        $render = $this->renderMode($leafState);
        $items = $sectionData['inspections'] ?? [];

        return [
            'label' => 'Statutory Inspections',
            'render_mode' => $render['mode'],
            'declaration' => $render['declaration'] ?? null,
            'items' => array_map(fn (array $i) => [
                'date' => $this->formatDate($i['inspection_date'] ?? null),
                'inspection_type' => $i['inspection_type'],
                'subject' => $i['subject_description'],
                'plant' => $i['plant']['name'] ?? '—',
                'reference' => $i['reference'] ?? '—',
                'outcome' => $this->humanize($i['outcome']),
                'status' => $this->humanize($i['status']),
                'next_due' => $this->formatDate($i['next_due_date'] ?? null),
            ], $items),
        ];
    }

    private function plantEquipment(array $items): array
    {
        return array_map(fn (array $p) => [
            'name' => $p['name'],
            // R1G.1-VFIX5 — presentation-only humanisation (e.g. the
            // stored PlantItem::$type value "access_equipment" displays
            // as "Access equipment") — the stored enum value itself is
            // never modified.
            'type' => $this->humanize($p['type']),
            'identifier' => $p['identifier'] ?? '—',
            'owner_supplier' => $p['owner_supplier'] ?? '—',
            'presence_periods' => array_map(fn (array $period) => [
                'on_site_from' => $this->formatDate($period['on_site_from']),
                'off_site_at'  => $period['off_site_at'] ? $this->formatDate($period['off_site_at']) : 'On site',
            ], $p['presence_periods'] ?? []),
        ], $items);
    }

    private function materials(array $items): array
    {
        return array_map(fn (array $m) => [
            'date' => $this->formatDate($m['date'] ?? null),
            'day_name' => $m['day_name'] ?? null,
            'materials_delivered' => $m['materials_delivered'],
        ], $items);
    }

    /**
     * Sign Off is read entirely LIVE from the FridayPack model's own
     * lifecycle columns — never from the frozen snapshot (see class
     * docblock). No signature/e-signature claim — this codebase has no
     * signing mechanism.
     */
    private function signOff(FridayPack $pack): array
    {
        return [
            'prepared_by' => $pack->generation_source === 'scheduled' ? 'SureSign Automation' : ($pack->generatedBy?->name ?? '—'),
            'prepared_at' => $pack->generated_at ? $this->formatLongDate($pack->generated_at) : '—',
            'reviewed_by' => $pack->reviewedBy?->name,
            'reviewed_at' => $pack->reviewed_at ? $this->formatLongDate($pack->reviewed_at) : null,
            'approved_by' => $pack->approvedBy?->name,
            'approved_at' => $pack->approved_at ? $this->formatLongDate($pack->approved_at) : null,
            'sent_by'     => $pack->sentBy?->name,
            'sent_at'     => $pack->sent_at ? $this->formatLongDate($pack->sent_at) : null,
        ];
    }

    // ── Formatting helpers ───────────────────────────────────────────────

    private function statusLabel(string $status): string
    {
        // Exact wording reused verbatim from FridayPackPdfPresenter's own
        // schema-1 convention — never re-authored.
        return match ($status) {
            'draft'            => 'DRAFT — NOT YET APPROVED',
            'ready_for_review' => 'READY FOR REVIEW',
            'approved'         => 'APPROVED',
            'sent'             => 'SENT',
            'failed'           => 'GENERATION FAILED',
            default            => strtoupper($status),
        };
    }

    private function humanize(?string $value): string
    {
        return $value ? ucfirst(str_replace('_', ' ', $value)) : '—';
    }

    /** Compact table-cell date — e.g. "21/08/2026". */
    private function formatDate($date): ?string
    {
        if (!$date) {
            return null;
        }
        $carbon = $date instanceof Carbon ? $date : Carbon::parse($date);

        return $carbon->format('d/m/Y');
    }

    /** Headline/report-metadata date — e.g. "21 August 2026". */
    private function formatLongDate($date): string
    {
        if (!$date) {
            return '—';
        }
        $carbon = $date instanceof Carbon ? $date : Carbon::parse($date);

        return $carbon->format('d F Y');
    }
}
