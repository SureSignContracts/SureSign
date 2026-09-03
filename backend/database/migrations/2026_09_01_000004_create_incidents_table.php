<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Friday Pack Realignment, R1E.2B — Incidents / Accidents / Near Misses.
 * The second of the six H&S domain tables approved by the R1E.2 corrected
 * schema checkpoint — only this one is implemented in this phase.
 *
 * ONE ROW = ONE INDEPENDENTLY RECORDED SAFETY EVENT. Deliberately
 * privacy-minimal — no injured-person identity, no worker/medical record
 * anywhere in this schema. `SiteDiary::$issues` remains the routine daily
 * narrative field and is never repurposed as this register.
 *
 * `occurred_at` is a genuine UTC instant (`datetime`, not `date`) —
 * unlike every other Friday Pack H&S source date so far (SiteDiary/
 * ToolboxTalk/SiteInduction all use plain DATE columns with no timezone
 * concern), this is the first field in this whole initiative that
 * actually needs the established `App\Services\TimezoneResolver`
 * architecture — see FridayPackIncidentSourceService for the full local
 * Monday-Friday to UTC boundary conversion this requires.
 *
 * `injury_occurred` is a NULLABLE boolean with NO default — `null` means
 * unknown/unconfirmed, `false` means explicitly confirmed no injury,
 * `true` means injury occurred. A DB-level default of `false` would
 * silently collapse "unknown" into "confirmed no injury," a real safety-
 * record honesty defect — deliberately omitted.
 *
 * `regulatory_reportability` (`unknown`/`not_reportable`/`reportable`) is
 * always a MANUAL classification — SureSign never auto-determines legal
 * reportability, and this is deliberately generic (never `riddor_status`
 * — RIDDOR is UK-specific, SureSign is worldwide). Separate from
 * `status` (`open`/`closed`, pure lifecycle) — an Incident may validly be
 * `type=accident, injury_occurred=true, regulatory_reportability=reportable,
 * status=open` all at once, four independent facts.
 *
 * FK delete semantics mirror `site_inductions`/`toolbox_talks` exactly:
 * organization_id/project_id cascadeOnDelete; created_by constrained
 * with no onDelete (non-cascading — Users are soft-deleted in practice,
 * never hard-removed).
 *
 * Explicit short index name (`inc_project_occurred_index`, 26 characters)
 * given proactively per this initiative's established discipline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');

            $table->dateTime('occurred_at');
            $table->string('type')->comment('accident, incident, near_miss');

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('location')->nullable();

            $table->boolean('injury_occurred')->nullable();
            $table->string('regulatory_reportability')->default('unknown')
                ->comment('unknown, not_reportable, reportable — manual classification only, never auto-determined');
            $table->string('status')->default('open')->comment('open, closed');

            $table->softDeletes();
            $table->timestamps();

            $table->index(['project_id', 'occurred_at'], 'inc_project_occurred_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
