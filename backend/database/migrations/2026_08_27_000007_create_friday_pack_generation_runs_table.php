<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automated Friday Pack, V1E — mirrors deadline_reminder_runs' exact
 * shape/reasoning (see that migration's own docblock): a durable,
 * DB-backed, per-project/per-week checkpoint that survives independently
 * of the FridayPack row itself. This is what stops an automatically
 * generated Draft that a human later deletes from being silently
 * recreated by the next hourly tick — using FridayPack existence alone
 * as the checkpoint would not survive that deletion.
 *
 * UNIQUE(project_id, week_ending) is the authoritative correctness
 * boundary for "has this project/week already been processed by
 * automation" — not a cache lock, not queue-level uniqueness (both may
 * still be used as efficiency optimizations, never as the guarantee).
 *
 * `completed_at` distinguishes a genuinely finished run from a
 * started-but-not-yet-finished or failed one — mirrors
 * DeadlineReminderRun::isComplete()'s exact semantics, which is what
 * lets a failed attempt be safely retried without ever producing two
 * completed runs for the same project/week.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('friday_pack_generation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->date('week_ending');
            $table->string('timezone');
            $table->unsignedTinyInteger('generation_hour_local');
            $table->foreignId('friday_pack_id')->nullable()->constrained('friday_packs')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'week_ending'], 'friday_pack_generation_runs_project_week_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('friday_pack_generation_runs');
    }
};
