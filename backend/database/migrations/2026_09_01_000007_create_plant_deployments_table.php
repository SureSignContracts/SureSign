<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Friday Pack Realignment, R1E.2D — Plant & Equipment. PLANT DEPLOYMENT =
 * a period during which a reusable Plant Item was physically on site.
 * ONE ROW = ONE CONTINUOUS PHYSICAL PRESENCE PERIOD — a crane on site for
 * six weeks is one row, never one row per day; if it leaves and later
 * returns, that is two separate, non-overlapping rows.
 *
 * `on_site_from` (required) / `off_site_at` (nullable — null means
 * currently/open-ended on site) are plain DATE columns — site-calendar
 * presence dates, deliberately no datetime/timezone complexity (unlike
 * `Incident::$occurred_at`). No separate `deployment_status` column — the
 * date range itself already expresses open-vs-closed cleanly.
 *
 * Overlap between two deployments for the SAME `plant_item_id` is
 * enforced APPLICATION-level only (R1E.2's own explicit approved
 * decision) — MySQL has no clean native exclusion constraint for
 * arbitrary date ranges, and this migration deliberately does not
 * introduce a trigger/generated-column/stored-procedure workaround to
 * simulate one. See App\Services\FridayPack\PlantDeploymentOverlapService
 * (or its concurrency-safe equivalent) for the one authoritative overlap
 * check.
 *
 * `plant_item_id` uses `restrictOnDelete()` (not `cascadeOnDelete()`) —
 * deliberately protects deployment history against an accidental
 * PHYSICAL parent deletion; `PlantItem` uses SoftDeletes, so normal UX
 * deletion never reaches this FK anyway, but the FK still guards against
 * a direct/manual hard delete silently erasing history. `organization_id`/
 * `project_id` remain cascadeOnDelete, matching every other Project-owned
 * table in this codebase — a deployment has no independent meaning once
 * its Project is gone.
 *
 * Explicit short index names given proactively (`pd_project_presence_index`,
 * `pd_item_onsite_index`) per this initiative's established discipline —
 * both were already identified as approaching the 64-character limit
 * during the original R1E audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plant_deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plant_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users');

            $table->date('on_site_from');
            $table->date('off_site_at')->nullable();
            $table->text('notes')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['project_id', 'on_site_from', 'off_site_at'], 'pd_project_presence_index');
            $table->index(['plant_item_id', 'on_site_from'], 'pd_item_onsite_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plant_deployments');
    }
};
