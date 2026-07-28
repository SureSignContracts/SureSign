<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G4C.1 — AI Usage Telemetry Foundation. Mirrors
 * 2026_08_09_000001_add_telemetry_fields_to_contract_ai_analyses_table.php —
 * trade_package_ai_analyses is structurally identical to contract_ai_analyses
 * (see internal-docs/super-admin/ai-credits-architecture.md §6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trade_package_ai_analyses', function (Blueprint $table) {
            $table->string('workflow', 50)->nullable()->after('model');
            $table->unsignedInteger('document_char_count')->nullable()->after('document_hash');
            $table->string('document_file_type', 10)->nullable()->after('document_char_count');
            $table->boolean('provider_called')->nullable()->after('stop_reason');
            $table->unsignedInteger('duration_ms')->nullable()->after('completed_at');
            $table->unsignedInteger('queue_attempt')->nullable()->after('duration_ms');
            $table->boolean('is_final_attempt')->nullable()->after('queue_attempt');
            $table->string('failure_category', 30)->nullable()->after('error_message');
        });

        DB::table('trade_package_ai_analyses')->update(['workflow' => 'trade_package_analysis']);
    }

    public function down(): void
    {
        Schema::table('trade_package_ai_analyses', function (Blueprint $table) {
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
