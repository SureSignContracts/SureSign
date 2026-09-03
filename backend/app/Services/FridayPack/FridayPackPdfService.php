<?php

namespace App\Services\FridayPack;

use App\Models\Document;
use App\Models\FridayPack;
use App\Models\User;
use App\Services\DocumentGenerationService;
use App\Services\ProjectActivityService;
use App\Support\FridayPack\FridayPackPdfPresenter;
use App\Support\FridayPack\FridayPackSchemaTwoPdfPresenter;
use App\Support\FridayPack\FridayPackSchemaVersion;
use App\Support\FridayPack\FridayPackUnsupportedSchemaVersionException;
use Illuminate\Support\Facades\DB;

/**
 * Automated Friday Pack, V1C — the one authoritative service turning a
 * persisted FridayPack into a branded PDF via the EXISTING
 * DocumentGenerationService pipeline. No DomPDF calls or Storage writes
 * happen here directly — generatePdf() owns all of that, including
 * branding header/footer injection.
 *
 * CRITICAL: every value passed into the Blade view comes from
 * FridayPackPdfPresenter, which reads only the persisted FridayPack row
 * (snapshot_json/settings_snapshot_json/commentary/lifecycle metadata).
 * No live business model or aggregation service is queried here — the
 * frozen snapshot already fed by FridayPackSnapshotService at generation
 * time is the sole source of report content.
 *
 * Document versioning: DocumentVersion has no genuinely active
 * generation/version workflow anywhere in this codebase (confirmed — no
 * call site creates one). This service therefore creates a new Document
 * row per explicit PDF generation/regeneration and keeps
 * FridayPack.pdf_document_id pointed at the current one; previous
 * Document rows/files are never deleted, matching
 * DocumentGenerationService's own existing "each generation is a new
 * Document" convention used by every other PDF in this codebase.
 *
 * **R1A.1 — Snapshot Schema Version Boundary (2026-08-31).** `presenter`
 * and `pdfs.friday-pack` are the pre-realignment, schema-1-only (legacy
 * management-report) presenter/template — frozen, unchanged, and reused
 * exactly as-is for any pack whose `snapshot_json['schema_version']` is
 * `FridayPackSchemaVersion::LEGACY_VERSION`.
 *
 * **R1G.1 — Schema-2 PDF Implementation.** The schema-2 rendering pipeline
 * (`FridayPackSchemaTwoPdfPresenter`, `pdfs.friday-pack-v2` + partials,
 * `FridayPackPhotoPdfOptimisationService`, and the schema-2-only
 * page-numbering/repeating-chrome additions to `DocumentGenerationService`)
 * is fully built and covered by `FridayPackSchemaTwoPdfTest`/
 * `FridayPackPhotoPdfRenderingTest`.
 *
 * **`SCHEMA_TWO_LIVE` is now `true` (R1G.1-ACT, 2026-09-03).** R1G.1
 * through R1G.1-VFIX7 iterated the schema-2 template against real,
 * externally-rasterised visual QA (this development environment has no
 * PDF rasteriser itself — `poppler-utils`/`pdftoppm`/ImageMagick/
 * Ghostscript are all confirmed absent, and generated fixtures were
 * delivered out of the environment for page-by-page inspection each
 * round). The V7 artifacts passed final external visual QA against the
 * boss-provided construction reference — see project-context.md's
 * R1G.1-ACT entry for the full activation record. Real photo embedding
 * was proven visually correct in the earlier V5/V6 rounds; V7 itself
 * could not re-exercise it only because this session's own container has
 * no working JPEG GD codec (`imagejpeg()`/`imagecreatefromjpeg()` both
 * absent) — a confirmed development-environment limitation, not a defect
 * in the (unmodified) photo pipeline.
 */
class FridayPackPdfService
{
    /**
     * R1G.1-ACT (2026-09-03) — flipped to `true`. Final external visual QA
     * of the V7 artifacts passed (see this class's own docblock history
     * and project-context.md's R1G.1-ACT entry for the full record). The
     * schema-version branch below is unchanged — schema-1 still routes to
     * the legacy presenter/template, and a genuinely future/unsupported
     * schema still throws `FridayPackUnsupportedSchemaVersionException`
     * exactly as before.
     */
    private const SCHEMA_TWO_LIVE = true;

    public function __construct(
        private FridayPackPdfPresenter $presenter,
        private FridayPackSchemaTwoPdfPresenter $schemaTwoPresenter,
        private FridayPackPhotoPdfOptimisationService $photoOptimiser,
    ) {}

    /**
     * Generates (or regenerates) the current PDF for a FridayPack.
     * Failure safety: the new Document is only linked to the pack (via
     * pdf_document_id) AFTER generatePdf() has already returned
     * successfully — an exception from generatePdf() propagates out
     * untouched, leaving any existing pdf_document_id exactly as it was.
     */
    public function generate(FridayPack $pack, User $actor): Document
    {
        // V1F: once any delivery row exists for this pack, its PDF is
        // locked — the distributed document must never be silently
        // regenerated/repointed out from under a link already sent to a
        // recipient. This check applies regardless of pack status.
        if ($pack->deliveries()->exists()) {
            throw new \App\Support\FridayPack\FridayPackDeliveryLockedException('This Friday Pack has already been sent and its PDF can no longer be regenerated.');
        }

        // R1G.1: the schema-2 pipeline is built but deliberately not yet
        // live — see this class's own docblock (SCHEMA_TWO_LIVE). Only
        // LEGACY_VERSION renders while that stays false; CURRENT_VERSION
        // and any genuinely future version are both refused identically.
        $schemaVersion = $pack->snapshot_json['schema_version'] ?? null;
        $schemaTwoLive = self::SCHEMA_TWO_LIVE && FridayPackSchemaVersion::isCurrent($schemaVersion);
        if (!FridayPackSchemaVersion::isLegacy($schemaVersion) && !$schemaTwoLive) {
            throw new FridayPackUnsupportedSchemaVersionException(
                'Friday Pack PDF generation is temporarily unavailable for this Friday Pack format until the report template is updated.'
            );
        }

        $isRegeneration = $pack->pdf_document_id !== null;
        $project = $pack->project;
        $tempFiles = [];
        $repeatingChrome = null;

        if (FridayPackSchemaVersion::isLegacy($schemaVersion)) {
            $viewName = 'pdfs.friday-pack';
            $viewData = ['pack' => $this->presenter->present($pack)];
        } else {
            $presented = $this->schemaTwoPresenter->present($pack);
            $viewName = 'pdfs.friday-pack-v2';
            $viewData = ['pack' => $presented['data']];
            $tempFiles = $presented['temp_files'];

            // R1G.1-VFIX6 — the repeating text header/footer is now drawn
            // by DocumentGenerationService's canvas mechanism, not CSS
            // `position: fixed` (found unreliable on continuation pages —
            // see that method's own docblock for the proven root cause).
            // Every string here is pre-formatted, safe display text.
            $info = $presented['data']['report_information'];
            $identityLine = trim(($info['project_name'] ?? $project->name)
                . ($info['report_number'] ? " — Report {$info['report_number']}" : '')
                . " — Week Ending {$presented['data']['week_ending_label']}");
            $repeatingChrome = [
                'header_left'    => $presented['data']['organisation_display_name'],
                'header_right'   => 'WEEKLY FRIDAY PACK',
                'header_subline' => $identityLine,
                'footer_left'    => $identityLine,
                'footer_center'  => 'Generated with SureSign',
            ];
        }

        $weekEndingSlug = $pack->week_ending->format('Y-m-d');
        $reportNumberSuffix = $pack->report_number ? " — Report {$pack->report_number}" : '';
        $title = "Friday Pack — {$project->name}{$reportNumberSuffix} — Week Ending " . $pack->week_ending->format('d F Y');
        $reference = 'FP-' . $weekEndingSlug;

        try {
            // generatePdf() itself throws on any rendering/storage failure —
            // deliberately NOT caught here, so a failure propagates to the
            // caller and pdf_document_id is never touched (see generate()
            // callers in FridayPackController).
            $document = DocumentGenerationService::generatePdf(
                $project,
                $actor,
                $viewName,
                $viewData,
                $title,
                'friday_pack',
                '12_Friday_Packs',
                $reference,
                $pack,
                // R1G.1 — page numbering is a schema-2-only addition;
                // schema-1's own generated output is completely unchanged
                // (the new parameter defaults false).
                includePageNumbers: FridayPackSchemaVersion::isCurrent($schemaVersion),
                // R1G.1-VFIX2 — positions the page number within
                // pdfs/friday-pack-v2.blade.php's own compact repeating
                // footer band (comfortable for the common case of no
                // organisation-configured footer letterhead image; a
                // configured footer image would still occupy the lower
                // portion of a taller margin in that case — see that
                // Blade's own docblock for the known, accepted limitation
                // this carries forward, unchanged from R1G.1).
                pageNumberYFromBottomPx: FridayPackSchemaVersion::isCurrent($schemaVersion) ? 20 : null,
                // R1G.1-VFIX5 — aligns the page number's right edge with
                // .report-content's own 15mm (≈56.7px) inset, plus enough
                // reserve for "Page X of Y" text width, so it shares the
                // same right-hand boundary as ordinary body content
                // instead of drifting to the physical page edge.
                pageNumberXFromRightPx: FridayPackSchemaVersion::isCurrent($schemaVersion) ? 135 : null,
                // R1G.1-VFIX6 — see $repeatingChrome's own construction
                // above; null for schema-1, completely unchanged.
                repeatingChrome: $repeatingChrome,
            );
        } finally {
            // R1G.1 — best-effort cleanup of any temporary photo
            // renditions the schema-2 presenter produced, regardless of
            // whether generation above succeeded or threw. A no-op for
            // schema-1 (never produces temp files) and for a schema-2
            // pack with no photographs.
            if (!empty($tempFiles)) {
                $this->photoOptimiser->cleanup($tempFiles);
            }
        }

        DB::transaction(function () use ($pack, $document) {
            // Direct column update (not $pack->update()) — deliberately
            // bypasses FridayPackIntegrityGuard's own protected-field list,
            // which does not include pdf_document_id (a lifecycle field,
            // not frozen report content) — see that guard's own docblock.
            $pack->forceFill(['pdf_document_id' => $document->id])->save();
        });

        ProjectActivityService::record(
            $project,
            $actor,
            $isRegeneration ? 'friday_pack_pdf_regenerated' : 'friday_pack_pdf_generated',
            ($isRegeneration ? 'Friday Pack PDF regenerated for week ending ' : 'Friday Pack PDF generated for week ending ')
                . $pack->week_ending->toDateString(),
            null,
            $pack,
        );

        return $document->fresh();
    }
}
