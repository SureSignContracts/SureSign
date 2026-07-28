<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G4C.1 — AI Usage Telemetry Foundation. Purely additive: no ledger,
 * no balance, no credit fields. See
 * internal-docs/super-admin/ai-credits-architecture.md for the full
 * investigation this closes the telemetry gaps from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_ai_analyses', function (Blueprint $table) {
            // Normalized workflow identifier — see App\Support\AI\AiWorkflow.
            $table->string('workflow', 50)->nullable()->after('model');
            // Length of the extracted document text at analyse-time (chars, not bytes).
            $table->unsignedInteger('document_char_count')->nullable()->after('document_hash');
            // File extension of the analysed upload (pdf/docx/txt), lowercase, no dot.
            $table->string('document_file_type', 10)->nullable()->after('document_char_count');
            // Whether Claude was actually invoked for this analysis, vs. an exact
            // document_hash+model cache reuse. Null until the analysis reaches that
            // decision point (e.g. it failed validation before then).
            $table->boolean('provider_called')->nullable()->after('stop_reason');
            // completed_at - started_at in milliseconds. Set once, at the terminal
            // (completed/failed) transition, by the owning job.
            $table->unsignedInteger('duration_ms')->nullable()->after('completed_at');
            // Laravel queue attempt number this run represents, and whether it was
            // the last attempt allowed ($tries). Both jobs use $tries = 1, so this
            // mainly documents that no queue-level retry occurred.
            $table->unsignedInteger('queue_attempt')->nullable()->after('duration_ms');
            $table->boolean('is_final_attempt')->nullable()->after('queue_attempt');
            // Structured failure classification — see App\Support\AI\AiFailureCategory.
            // Only ever set when status = 'failed'.
            $table->string('failure_category', 30)->nullable()->after('error_message');
        });

        DB::table('contract_ai_analyses')->update(['workflow' => 'contract_analysis']);
    }

    public function down(): void
    {
        Schema::table('contract_ai_analyses', function (Blueprint $table) {
            $table->dropColumn([
                'workflow',
                'document_char_count',
                'document_file_type',
                'provider_called',
                'duration_ms',
                'queue_attempt',
                'is_final_attempt',
                'failure_category',
            ]);
        });
    }
};
