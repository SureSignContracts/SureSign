<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Friday Pack Realignment, R1E.2A — Site Inductions. The first of the six
 * H&S domain tables approved by the R1E.2 corrected schema checkpoint —
 * only this one is implemented in this phase.
 *
 * ONE ROW = ONE INDUCTION SESSION — never one individual worker, never a
 * meaning-shifting daily aggregate. Two sessions on the same date are two
 * rows; no uniqueness by date. No individual worker identity anywhere in
 * this schema.
 *
 * FK delete semantics mirror `toolbox_talks` (2026_08_27_000002 — the
 * most recent, cleanest precedent for a brand-new project-scoped module)
 * exactly, per that migration's own documented reasoning:
 *  - organization_id/project_id: required, cascadeOnDelete — matches
 *    every project-owned table.
 *  - created_by: constrained('users') with NO onDelete — not cascading;
 *    Users are soft-deleted in normal application flow (never hard-
 *    removed), so this is the safer choice for historical records.
 *
 * `induction_date` is a plain DATE (not datetime) — the Friday Pack
 * reports induction activity by construction working day, mirroring
 * `SiteDiary::$diary_date`'s own convention.
 *
 * Explicit short index name (`sind_project_date_index`, 23 characters)
 * given proactively per this initiative's established R1B.3/R1C/R1D/R1E.2
 * discipline, even though Laravel's own auto-generated name
 * (`site_inductions_project_id_induction_date_index`, 49 characters)
 * would already have fit safely within the 64-character limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_inductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');

            $table->date('induction_date');
            $table->string('session_title')->nullable();
            $table->string('company_or_trade')->nullable();
            $table->unsignedInteger('inductee_count');
            $table->text('notes')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['project_id', 'induction_date'], 'sind_project_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_inductions');
    }
};
