<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Batch 7: stable identity for an individual reminder email, so a
     * retried/overlapping run can never send the same specific reminder
     * twice. The org-level checkpoint (deadline_reminder_runs) stops a
     * whole day's pass from repeating; this stops any one (source,
     * field, offset, deadline) combination within that pass from
     * repeating too — the two operate at different granularities and
     * both are needed (Batch 7 Phase 4 vs Phase 7).
     *
     * `effective_deadline_date` (not just "the day the email went out") is
     * part of the unique key deliberately: if the underlying deadline is
     * later changed (e.g. a payment application's due_date moves), that's
     * a genuinely new deadline and must be allowed to generate its own
     * reminder — not be suppressed because a reminder already fired for
     * the OLD date.
     */
    public function up(): void
    {
        Schema::create('deadline_reminder_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->string('reminder_field');
            $table->unsignedSmallInteger('reminder_offset_days');
            $table->date('effective_deadline_date');
            $table->timestamps();

            $table->unique(
                ['source_type', 'source_id', 'reminder_field', 'reminder_offset_days', 'effective_deadline_date'],
                'reminder_sends_identity_unique'
            );
            $table->index(['organization_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deadline_reminder_sends');
    }
};
