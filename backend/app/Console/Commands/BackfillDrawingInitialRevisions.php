<?php

namespace App\Console\Commands;

use App\Models\Drawing;
use App\Models\DrawingRevision;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4 — Drawing Revision Foundation. Manual/on-demand only (same
 * convention as ai:credits:backfill-simulations/entitlements:refresh-
 * capability-rollout/domains:verify-pending) — never scheduled, since a
 * Drawing's own document_id only changes at registration time, not on any
 * recurring cadence.
 *
 * Gives every pre-existing Drawing (created before revision history
 * existed) a real initial DrawingRevision so current_revision_id becomes
 * populated, and the Revision History UI has at least one honest row
 * instead of "no revisions yet" for a Drawing that genuinely has a file.
 *
 * NEVER invents revision_code/status/issued_date/issued_by — all four are
 * left null on the migrated row (Part F). "Unknown means unknown": the
 * frontend renders a null revision_code as "Revision not recorded", never
 * a fabricated code like "P01"/"Rev 0"/"Initial".
 *
 * Idempotent: only processes Drawings with current_revision_id still null
 * (a retried/resumed run, or a Drawing that already has real revision
 * history from Add Revision, is never touched twice).
 */
class BackfillDrawingInitialRevisions extends Command
{
    protected $signature = 'drawings:backfill-initial-revisions
        {--limit=500 : Maximum Drawings to process in this run}
        {--dry-run : Report what would happen without writing anything}';

    protected $description = 'Give every pre-existing Drawing (created before revision history existed) an initial DrawingRevision, with no invented revision code/status/date';

    public function handle(): int
    {
        $limit = max(1, (int) ($this->option('limit') ?: 500));
        $dryRun = (bool) $this->option('dry-run');

        $drawings = Drawing::whereNull('current_revision_id')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($drawings->isEmpty()) {
            $this->info('No Drawings need an initial revision — nothing to do.');

            return self::SUCCESS;
        }

        $this->line("Found {$drawings->count()} Drawing(s) with no current revision.");
        $created = 0;
        $skippedNoDocument = 0;

        foreach ($drawings as $drawing) {
            if (! $drawing->document_id) {
                // Should not be possible (document_id is NOT NULL — Phase
                // 1A), but never guess a Document if this invariant is
                // somehow violated for a given row.
                $this->warn("  Drawing #{$drawing->id} ({$drawing->drawing_number}) has no document_id — skipped.");
                $skippedNoDocument++;

                continue;
            }

            if ($dryRun) {
                $this->line("  Drawing #{$drawing->id} ({$drawing->drawing_number}): would create initial revision from document #{$drawing->document_id}");
                $created++;

                continue;
            }

            DB::transaction(function () use ($drawing) {
                $revision = DrawingRevision::create([
                    'drawing_id' => $drawing->id,
                    'document_id' => $drawing->document_id,
                    'revision_code' => null,
                    'status' => null,
                    'issued_date' => null,
                    'issued_by' => null,
                    'notes' => null,
                    'created_by' => $drawing->created_by,
                ]);

                $drawing->update(['current_revision_id' => $revision->id]);
            });

            $this->line("  Drawing #{$drawing->id} ({$drawing->drawing_number}): initial revision created (unrecorded).");
            $created++;
        }

        $this->newLine();
        $this->info(($dryRun ? '[dry-run] Would create' : 'Created')." {$created} initial revision(s).".($skippedNoDocument ? " Skipped {$skippedNoDocument} with no document_id." : ''));

        return self::SUCCESS;
    }
}
