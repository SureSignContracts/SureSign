<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Toolbox Talks V1A — a short construction-site safety briefing record.
 * Mirrors the Snag/QaReport table shape (the most recent, cleanest
 * precedent for a brand-new project-scoped module) rather than the older
 * site_diaries table.
 *
 * FK delete semantics, resolved from existing convention rather than
 * guessed:
 *  - organization_id/project_id: required, cascadeOnDelete — matches
 *    every project-owned table (Snag, QaReport, site_diaries all agree).
 *  - created_by: constrained('users') with NO onDelete — deliberately
 *    NOT cascade. Snag/QaReport use cascade here, but SiteDiary (the
 *    module this feature most directly extends) does not, and Users are
 *    soft-deleted in normal application flow (never hard-removed), so
 *    this constraint is not reachable in practice either way — choosing
 *    the non-cascading option is the safer one for historical Toolbox
 *    Talk records, per the explicit instruction to prefer the safest
 *    convention for this field.
 *  - delivered_by_user_id: nullable, constrained('users'), nullOnDelete —
 *    matches Snag.assigned_to / QaReport.inspected_by exactly. The talk
 *    record must survive removal of the presenter's user account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('toolbox_talks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('delivered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->date('talk_date');
            $table->time('started_at')->nullable();
            $table->string('location')->nullable();
            $table->string('delivered_by_name')->nullable();
            $table->string('trade_or_subcontractor')->nullable();
            $table->text('summary')->nullable();
            $table->enum('status', ['draft', 'submitted', 'approved'])->default('draft');
            $table->unsignedInteger('attendee_count')->default(0);
            $table->timestamps();

            $table->index(['project_id', 'talk_date']);
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('toolbox_talks');
    }
};
