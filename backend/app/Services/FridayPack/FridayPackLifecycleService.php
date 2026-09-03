<?php

namespace App\Services\FridayPack;

use App\Models\FridayPack;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectActivityService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Automated Friday Pack, V1D — the ONE authoritative service for every
 * Review/Approval lifecycle transition. Mirrors
 * FridayPackGenerationService's own transaction+row-lock pattern (itself
 * mirroring the P2 AI Analysis TOCTOU fix) — no transition logic is
 * reproduced in the controller.
 *
 * Transition map:
 *   draft            --submitForReview()--> ready_for_review
 *   ready_for_review --markReviewed()-----> ready_for_review (+ reviewed_at/by)
 *   ready_for_review --returnToDraft()----> draft
 *   ready_for_review --approve()----------> approved   (requires reviewed_at/by already set)
 *
 * `sent` is reserved for a later delivery phase — no transition here ever
 * produces or accepts it.
 *
 * Review-cycle freshness is a structural invariant, not a separate
 * mechanism: submitForReview() and returnToDraft() both clear
 * reviewed_at/reviewed_by (and approved_at/approved_by) explicitly, so
 * approve()'s own "reviewed_at IS NOT NULL AND reviewed_by IS NOT NULL"
 * check can never see stale metadata from a prior cycle — no review
 * version numbers or cycle ids are needed.
 *
 * PDF generation is never triggered from any method here — a lifecycle
 * transition's success must never depend on DomPDF/storage availability
 * (V1D spec). Every transition that changes report-visible content
 * (including lifecycle status/reviewer/approver metadata, since the PDF
 * displays it) clears pdf_document_id in the SAME save; the historical
 * Document row/file is never touched.
 */
class FridayPackLifecycleService
{
    public function __construct(
        private FridayPackPhotoSelectionService $photoSelections,
        private FridayPackReadinessService $readiness,
    ) {}

    /**
     * draft -> ready_for_review. Review metadata is (re-)cleared
     * explicitly even though it's already null on a genuine first
     * submission — this is what guarantees returnToDraft() + a second
     * submitForReview() can never leak a prior cycle's reviewed_at/by
     * into the new one.
     *
     * R1B evidence-integrity preflight: every currently selected photo
     * must still resolve to a real, tenant-valid FileUpload whose
     * physical file genuinely exists, checked BEFORE the transition
     * itself — never deferred to PDF generation time. A pack with zero
     * selected photos is never blocked by this (R1B does not require any
     * photos to exist — that's a later readiness phase's concern). This
     * is a pure validation call ahead of the existing transition(); it
     * changes no transition semantics, states, or locking.
     */
    /**
     * R1F.2 — the derived content-readiness gate runs FIRST, before the
     * existing photo-selection/evidence-integrity preflight. Readiness
     * is evaluated only for a schema-2 pack (schema-1 has no readiness
     * concept — see FridayPackReadinessService::evaluate()'s own
     * schema-version branch, mirrored here so a schema-1 pack never even
     * calls it). The pre-existing photo-selection check remains as
     * defense-in-depth for a narrower, independent concern (a selected
     * photo whose physical file has since vanished) — readiness only
     * checks that ≥1 photo was SELECTED, never that the selection is
     * still physically valid.
     */
    public function submitForReview(FridayPack $pack, User $actor): FridayPack
    {
        if ((int) ($pack->snapshot_json['schema_version'] ?? 1) === \App\Support\FridayPack\FridayPackSchemaVersion::CURRENT_VERSION) {
            $result = $this->readiness->evaluate($pack);
            if (!$result['ready']) {
                throw new \App\Support\FridayPack\FridayPackNotReadyException($result['blockers']);
            }
        }

        $this->photoSelections->assertAllSelectionsValid($pack);

        return $this->transition($pack, $actor, allowedFrom: ['draft'], apply: function (FridayPack $pack) {
            $pack->status = 'ready_for_review';
            $pack->reviewed_at = null;
            $pack->reviewed_by = null;
            $pack->approved_at = null;
            $pack->approved_by = null;
            $pack->pdf_document_id = null;
        }, activityType: 'friday_pack_submitted_for_review', activityMessage: 'Friday Pack submitted for review');
    }

    /**
     * ready_for_review -> ready_for_review (+ reviewed_at/by). Rejects a
     * second review of the same cycle (reviewed_at already set) — proven
     * via the same allowedFrom+extra-guard shape approve() uses.
     */
    public function markReviewed(FridayPack $pack, User $actor): FridayPack
    {
        return $this->transition($pack, $actor, allowedFrom: ['ready_for_review'], apply: function (FridayPack $pack) use ($actor) {
            if ($pack->reviewed_at !== null) {
                throw new RuntimeException('This Friday Pack has already been reviewed for the current review cycle.');
            }

            $pack->reviewed_at = now();
            $pack->reviewed_by = $actor->id;
            // Reviewer metadata is displayed in the PDF (V1D decision —
            // see FridayPackPdfPresenter) — completing review changes
            // report-visible content, so the current PDF is invalidated
            // exactly like any other report-visible change.
            $pack->pdf_document_id = null;
        }, activityType: 'friday_pack_reviewed', activityMessage: 'Friday Pack marked as reviewed');
    }

    /**
     * ready_for_review -> draft. Never allowed from approved — enforced
     * structurally by allowedFrom, not a separate check.
     */
    public function returnToDraft(FridayPack $pack, User $actor): FridayPack
    {
        return $this->transition($pack, $actor, allowedFrom: ['ready_for_review'], apply: function (FridayPack $pack) {
            $pack->status = 'draft';
            $pack->reviewed_at = null;
            $pack->reviewed_by = null;
            $pack->approved_at = null;
            $pack->approved_by = null;
            $pack->pdf_document_id = null;
        }, activityType: 'friday_pack_returned_to_draft', activityMessage: 'Friday Pack returned to draft');
    }

    /**
     * ready_for_review -> approved. Requires the CURRENT review cycle to
     * already be reviewed (reviewed_at/reviewed_by both set) — see class
     * docblock for why this alone is sufficient without a review-cycle id.
     * Once this succeeds, FridayPackIntegrityGuard makes every protected
     * field immutable (the guard checks the ORIGINAL status before this
     * save, which is still ready_for_review here, so this specific write
     * is never blocked by its own transition).
     */
    public function approve(FridayPack $pack, User $actor): FridayPack
    {
        return $this->transition($pack, $actor, allowedFrom: ['ready_for_review'], apply: function (FridayPack $pack) use ($actor) {
            if ($pack->reviewed_at === null || $pack->reviewed_by === null) {
                throw new RuntimeException('This Friday Pack must be reviewed before it can be approved.');
            }

            $pack->status = 'approved';
            $pack->approved_at = now();
            $pack->approved_by = $actor->id;
            $pack->pdf_document_id = null;
        }, activityType: 'friday_pack_approved', activityMessage: 'Friday Pack approved');
    }

    /**
     * Shared transaction+row-lock+validation+activity shape for every
     * transition above — mirrors FridayPackGenerationService::generate()'s
     * own pattern. Locks the FridayPack row itself (not the Project, since
     * these transitions only ever touch one specific pack, unlike
     * generate()'s project-wide create-or-find) for the duration of the
     * check+write, so two concurrent requests against the SAME pack can
     * never both observe the same starting status and both apply
     * conflicting transitions (e.g. concurrent Approve vs Return-to-Draft).
     *
     * @param  string[]  $allowedFrom
     * @param  callable(FridayPack): void  $apply  Mutates $pack in place;
     *   may throw RuntimeException for an additional business-rule
     *   rejection beyond the allowedFrom status check (double-review,
     *   approve-before-reviewed).
     *
     * @throws RuntimeException on an invalid transition (caller maps to 409).
     */
    private function transition(FridayPack $pack, User $actor, array $allowedFrom, callable $apply, string $activityType, string $activityMessage): FridayPack
    {
        return DB::transaction(function () use ($pack, $actor, $allowedFrom, $apply, $activityType, $activityMessage) {
            /** @var FridayPack $locked */
            $locked = FridayPack::whereKey($pack->id)->lockForUpdate()->firstOrFail();

            if (!in_array($locked->status, $allowedFrom, true)) {
                throw new RuntimeException(
                    "Friday Pack is currently \"{$locked->status}\" — this action requires status "
                    . (count($allowedFrom) === 1 ? "\"{$allowedFrom[0]}\"." : 'one of: ' . implode(', ', $allowedFrom) . '.')
                );
            }

            $apply($locked);
            $locked->save();

            $project = $locked->project ?? Project::findOrFail($locked->project_id);
            ProjectActivityService::record(
                $project,
                $actor,
                $activityType,
                $activityMessage . ' for week ending ' . $locked->week_ending->toDateString(),
                null,
                $locked,
            );

            return $locked->fresh();
        });
    }
}
