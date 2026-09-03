<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automated Friday Pack, V1B — the frozen weekly reporting snapshot itself.
 * UNIQUE(project_id, week_ending) is the authoritative idempotency guard —
 * see FridayPackGenerationService, which relies on this constraint rather
 * than a race-prone exists() check.
 *
 * generated_at/generated_by are NOT nullable — a FridayPack row only ever
 * comes into existence via generation, unlike reviewed_at/approved_at/
 * sent_at, which represent later, optional lifecycle steps not yet exposed
 * in V1B (status stays 'draft' for every pack created this phase).
 *
 * No SoftDeletes — historical reporting records should not casually
 * disappear (V1B decision); delete behaviour is enforced in application
 * logic (draft-only), not at the schema level, matching this codebase's
 * existing convention of authorization/workflow guards living in services/
 * controllers rather than as a blanket schema restriction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('friday_packs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->date('week_ending');
            $table->date('period_start');
            $table->date('period_end');

            $table->enum('status', ['draft', 'ready_for_review', 'approved', 'sent', 'failed'])->default('draft');

            $table->json('snapshot_json');
            $table->json('settings_snapshot_json');

            $table->text('executive_summary')->nullable();
            $table->text('progress_commentary')->nullable();
            $table->text('key_concerns')->nullable();
            $table->text('next_week_priorities')->nullable();

            $table->timestamp('generated_at');
            $table->foreignId('generated_by')->constrained('users');

            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('sent_at')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('pdf_document_id')->nullable()->constrained('documents')->nullOnDelete();

            $table->text('failure_reason')->nullable();

            $table->timestamps();

            $table->unique(['project_id', 'week_ending']);
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('friday_packs');
    }
};
