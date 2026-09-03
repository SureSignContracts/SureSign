<?php

namespace App\Services\FridayPack;

use App\Models\FridayPack;
use App\Models\FridayPackSettings;
use App\Models\Project;
use App\Models\User;
use App\Services\DocumentNumberService;
use App\Services\ProjectActivityService;
use App\Support\FridayPack\FridayPackSections;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Automated Friday Pack, V1B — the one authoritative service for creating
 * and regenerating a Friday Pack. Deliberately does NOT create a PDF or
 * send email (later phases) — this service's only job is: resolve the
 * period, load settings, collect a deterministic snapshot, and persist it.
 */
class FridayPackGenerationService
{
    public function __construct(
        private FridayPackSnapshotService $snapshotService,
        private DocumentNumberService $documentNumbers,
        private FridayPackSectionDeclarationService $declarations,
    ) {}

    /**
     * Creates a new Friday Pack draft for project_id+week_ending, or — if
     * one already exists and is still a draft — regenerates it in place
     * (refreshing snapshot_json/settings_snapshot_json/generated_at/
     * generated_by, preserving manual commentary unless explicitly
     * overwritten by the caller). Never creates a second row for the same
     * project_id+week_ending — the migration's own UNIQUE constraint is the
     * authoritative guard; this method additionally locks the parent
     * Project row for the duration of the check+write (mirrors the P2 AI
     * Analysis TOCTOU fix's exact pattern) so two concurrent requests for
     * the same project/week can never both observe "no existing row" and
     * both attempt an insert.
     *
     * @throws InvalidArgumentException when week_ending is not a Friday.
     * @throws RuntimeException when an existing pack for this week is
     *   already approved/sent (regeneration is prohibited past draft).
     */
    public function generate(Project $project, string $weekEndingDate, User $actor): FridayPack
    {
        $period = FridayPackPeriodResolver::resolve($weekEndingDate, $project->organization);
        $settings = $this->loadOrDefaultSettings($project);

        return DB::transaction(function () use ($project, $period, $settings, $actor) {
            // Lock the parent Project row for the duration of the
            // check+write — closes the same race window the P2 AI Analysis
            // TOCTOU fix closes for analysis creation (see CLAUDE.md).
            Project::whereKey($project->id)->lockForUpdate()->first();

            $existing = FridayPack::where('project_id', $project->id)
                ->whereDate('week_ending', $period['week_ending'])
                ->first();

            if ($existing) {
                // V1D: regeneration is a Draft-only action — once a pack
                // has moved to ready_for_review (or beyond), the snapshot
                // is under review/approved and must not be silently
                // refreshed; App\Services\FridayPack\FridayPackLifecycleService::returnToDraft()
                // is the only way back to a regeneratable state.
                if ($existing->status !== 'draft') {
                    throw new RuntimeException(
                        "Friday Pack for week ending {$period['week_ending']} is currently \"{$existing->status}\" and cannot be regenerated."
                    );
                }

                return $this->applySnapshot($existing, $project, $period, $settings, $actor, regenerated: true);
            }

            $pack = new FridayPack([
                'project_id'      => $project->id,
                'organization_id' => $project->organization_id,
                'week_ending'     => $period['week_ending'],
                'period_start'    => $period['period_start'],
                'period_end'      => $period['period_end'],
                'status'          => 'draft',
            ]);

            try {
                return $this->applySnapshot($pack, $project, $period, $settings, $actor, regenerated: false);
            } catch (QueryException $e) {
                // A concurrent request won the race between our own
                // existence check and this insert (extremely unlikely
                // given the row lock above, but the DB unique constraint —
                // not this check — is the true authority). Reuse the
                // now-existing row rather than surfacing a raw DB error.
                if ($this->isUniqueConstraintViolation($e)) {
                    $existing = FridayPack::where('project_id', $project->id)
                        ->whereDate('week_ending', $period['week_ending'])
                        ->firstOrFail();

                    return $existing;
                }

                throw $e;
            }
        });
    }

    /**
     * V1E — the ONE scheduled-generation entry point. CREATE-ONLY: if a
     * FridayPack already exists for this project_id+week_ending
     * (regardless of its status or how it was originally created), this
     * method does NOT touch it in any way and returns null — the caller
     * (GenerateScheduledFridayPackJob) is responsible for recording that
     * outcome in FridayPackGenerationRun. This method never calls
     * applySnapshot()/generate()'s own regeneration path — scheduled
     * automation must never regenerate an existing pack.
     *
     * No actor: generated_by = null, generation_source = 'scheduled' —
     * see the class docblock and the V1E migration for why no fake User
     * is ever used. No ProjectActivityService call either, for the same
     * reason (that API requires a real, non-nullable User) — the durable
     * FridayPackGenerationRun row plus normal application logging is the
     * complete audit trail for scheduled generation.
     *
     * @throws InvalidArgumentException when week_ending is not a Friday.
     */
    public function generateScheduledIfMissing(Project $project, string $weekEndingDate): ?FridayPack
    {
        $period = FridayPackPeriodResolver::resolve($weekEndingDate, $project->organization);
        $settings = $this->loadOrDefaultSettings($project);

        return DB::transaction(function () use ($project, $period, $settings) {
            // Same TOCTOU-closing row lock as generate() — see that
            // method's own docblock.
            Project::whereKey($project->id)->lockForUpdate()->first();

            $existing = FridayPack::where('project_id', $project->id)
                ->whereDate('week_ending', $period['week_ending'])
                ->first();

            if ($existing) {
                return null;
            }

            $includedSections = $settings->included_sections ?? FridayPackSections::defaults();

            // R1D: this method is create-only (never touches an existing
            // pack — see this method's own docblock), so allocating a
            // report number here is always safe/correct — it can only
            // ever run for a genuinely new pack.
            $reportNumber = $this->documentNumbers->allocateFridayPackSequence($project);
            $generatedAt = now();
            $reportMeta = [
                'prepared_by'   => 'SureSign Automation',
                'prepared_at'   => $generatedAt->toIso8601String(),
                'report_number' => $reportNumber,
            ];

            $pack = new FridayPack([
                'project_id'             => $project->id,
                'organization_id'        => $project->organization_id,
                'week_ending'            => $period['week_ending'],
                'period_start'           => $period['period_start'],
                'period_end'             => $period['period_end'],
                'status'                 => 'draft',
                'report_number'          => $reportNumber,
                'snapshot_json'          => $this->snapshotService->collect($project, $period, $includedSections, null, $reportMeta),
                'settings_snapshot_json' => ['enabled' => $settings->enabled, 'included_sections' => $includedSections],
                'generated_at'           => $generatedAt,
                'generated_by'           => null,
                'generation_source'      => 'scheduled',
            ]);

            try {
                $pack->save();

                return $pack->fresh();
            } catch (QueryException $e) {
                // A concurrent attempt (manual or another scheduled job)
                // won the race between our own check and this insert —
                // the DB unique constraint is the true authority. Treat
                // this identically to "already existed": create-only,
                // never touch whatever won the race.
                if ($this->isUniqueConstraintViolation($e)) {
                    return null;
                }

                throw $e;
            }
        });
    }

    private function applySnapshot(FridayPack $pack, Project $project, array $period, FridayPackSettings $settings, User $actor, bool $regenerated): FridayPack
    {
        $includedSections = $settings->included_sections ?? FridayPackSections::defaults();

        // R1D: the report number is allocated exactly ONCE, at
        // first-time creation only — never on regeneration. `!$pack->exists`
        // is the authoritative "first time" signal (mirrors the same
        // check already used below for photo-selection reads); a
        // pre-R1D pack that already existed before this column existed
        // stays without one forever (no retroactive backfill, matching
        // this migration's own no-backfill design).
        if (!$pack->exists) {
            $pack->report_number = $this->documentNumbers->allocateFridayPackSequence($project);
        }

        // R1D: resolved BEFORE collect() so Report Information's
        // "Prepared By"/"Prepared At" reflect THIS generation event, not
        // the previous one — see FridayPackSnapshotService::collect()'s
        // own docblock for why $existingPack->generated_at/by cannot be
        // trusted for this at collect()-time.
        $generatedAt = now();
        $reportMeta = [
            'prepared_by'   => $actor->name,
            'prepared_at'   => $generatedAt->toIso8601String(),
            'report_number' => $pack->report_number,
        ];

        // R1B: a regeneration passes the pack itself (it already has an
        // id, so its persisted photo selections — App\Models\
        // FridayPackPhotoSelection — must be read and preserved into the
        // refreshed snapshot); first-time creation passes null (no id
        // exists yet, so no selection could possibly reference it).
        $pack->snapshot_json = $this->snapshotService->collect($project, $period, $includedSections, $pack->exists ? $pack : null, $reportMeta);
        $pack->settings_snapshot_json = [
            'enabled'           => $settings->enabled,
            'included_sections' => $includedSections,
        ];
        $pack->generated_at = $generatedAt;
        $pack->generated_by = $actor->id;
        // V1E: any MANUAL generation/regeneration — including manually
        // regenerating a pack that was originally created by automation —
        // always sets source back to 'manual', since generated_at/
        // generated_by already describe the latest generation event, not
        // the pack's original origin. Historical proof that automation
        // originally processed this project/week lives in
        // FridayPackGenerationRun, not on the pack itself.
        $pack->generation_source = 'manual';
        $pack->status = $pack->exists ? $pack->status : 'draft';

        // V1C stale-PDF rule: a regeneration changes report-visible
        // content, so any current PDF no longer reflects it — clear the
        // pointer (never delete the historical Document/file itself).
        // Never applies on first-time creation, since pdf_document_id is
        // already null then.
        if ($regenerated) {
            $pack->pdf_document_id = null;
        }

        $pack->save();

        // R1F.2 — after the regenerated snapshot has been successfully
        // persisted (never before — an invalidation must never be based
        // on partially-built source data), invalidate any declaration
        // now contradicted by real new source data. Only regeneration
        // can possibly have a pre-existing declaration to contradict —
        // first-time creation never does. $actor is always the REAL user
        // who triggered this regeneration; the scheduler's own
        // create-only path never reaches this method at all, so no
        // fabricated system/automation actor is ever needed here.
        if ($regenerated) {
            $this->declarations->invalidateContradictoryDeclarations($pack, $actor);
        }

        ProjectActivityService::record(
            $project,
            $actor,
            $regenerated ? 'friday_pack_regenerated' : 'friday_pack_generated',
            ($regenerated ? 'Friday Pack regenerated for week ending ' : 'Friday Pack generated for week ending ')
                . $period['week_ending'],
            null,
            $pack,
        );

        return $pack->fresh();
    }

    private function loadOrDefaultSettings(Project $project): FridayPackSettings
    {
        return FridayPackSettings::firstOrNew(
            ['project_id' => $project->id],
            [
                'organization_id'   => $project->organization_id,
                'enabled'           => true,
                'included_sections' => FridayPackSections::defaults(),
            ],
        );
    }

    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        return in_array((int) $e->getCode(), [23000, 23505], true);
    }
}
