<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mirrors 2026_09_05_000001_add_progress_to_contract_ai_analyses_table.php —
// Trade Package Analysis gets the same progress columns for parity, since
// AnalyseTradePackageWithAiJob follows the exact same stage progression as
// AnalyseContractWithAiJob. Backend/data-layer only in this pass — no
// frontend progress UI is wired to these yet (SubcontractAiOnboardingModal
// keeps its existing spinner-only "analysing" step); these columns just
// mean the data already exists for a future UI to use, matching how other
// fields in this table have been added ahead of their consumer before.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trade_package_ai_analyses', function (Blueprint $table) {
            $table->unsignedTinyInteger('progress_percent')->default(0)->after('status');
            $table->string('progress_stage', 40)->nullable()->after('progress_percent');
            $table->string('progress_message')->nullable()->after('progress_stage');
            $table->timestamp('progress_updated_at')->nullable()->after('progress_message');
        });
    }

    public function down(): void
    {
        Schema::table('trade_package_ai_analyses', function (Blueprint $table) {
            $table->dropColumn(['progress_percent', 'progress_stage', 'progress_message', 'progress_updated_at']);
        });
    }
};
