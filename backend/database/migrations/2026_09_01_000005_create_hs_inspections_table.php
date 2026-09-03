<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Friday Pack Realignment, R1E.2C — H&S Inspections. The third of the six
 * H&S domain tables approved by the R1E.2 corrected schema checkpoint —
 * only this one is implemented in this phase.
 *
 * ONE ROW = ONE HEALTH & SAFETY INSPECTION/CHECK undertaken on the
 * Project. Deliberately NOT `QaReport` relabelled — that table's
 * pass/fail-shaped `status` lifecycle is quality/QC-oriented, not H&S;
 * this is a genuinely separate table, reusing only QaReport's
 * ENGINEERING PATTERN (same field shape, same FileUpload attachment
 * convention), never its meaning.
 *
 * `outcome` (`satisfactory`/`issues_found` — what the inspection found)
 * and `status` (`open`/`closed` — pure lifecycle) are deliberately two
 * independent columns, never one overloaded field — `outcome =
 * issues_found, status = closed` is a valid, meaningful combination
 * (issues were found, and the follow-up has since been closed).
 *
 * `inspection_type` is free text, deliberately never a hardcoded global
 * enum — SureSign is worldwide and inspection naming varies by project/
 * jurisdiction. `inspected_by` is free text, NOT a User FK — a
 * deliberate domain decision (see App\Models\HsInspection's own
 * docblock): a real inspector may be an external H&S consultant,
 * principal contractor personnel, or another competent person with no
 * SureSign account.
 *
 * FK delete semantics mirror `incidents`/`site_inductions`/
 * `toolbox_talks` exactly: organization_id/project_id cascadeOnDelete;
 * created_by constrained with no onDelete (non-cascading — Users are
 * soft-deleted in practice, never hard-removed).
 *
 * Explicit short index name (`hsi_project_date_index`, 22 characters)
 * given proactively per this initiative's established discipline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hs_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');

            $table->date('inspection_date');
            $table->string('inspection_type');
            $table->string('inspected_by');

            $table->string('outcome')->comment('satisfactory, issues_found');
            $table->string('status')->default('open')->comment('open, closed');

            $table->text('findings')->nullable();
            $table->text('actions')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['project_id', 'inspection_date'], 'hsi_project_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hs_inspections');
    }
};
