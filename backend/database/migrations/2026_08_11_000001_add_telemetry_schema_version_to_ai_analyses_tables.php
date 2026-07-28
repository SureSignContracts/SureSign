<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G4C.2D — versions the STRUCTURE of collected execution telemetry,
 * not the commercial policy applied to it (that's candidate_policy_version/
 * normalization_version on AiCreditSimulationResult, unchanged here).
 *
 * Null means "collected before this column existed" (i.e. before Phase
 * G4C.1's telemetry columns were introduced, 2026-08-09) — deliberately NOT
 * backfilled to 1, since a pre-G4C.1 row does not actually have the G4C.1
 * telemetry shape (provider_called/duration_ms/failure_category etc. are
 * null for those rows regardless of what this column says). Backfilling it
 * to 1 would misrepresent genuinely incomplete historical rows as
 * structurally current ones. See
 * App\Support\AI\AiTelemetrySchema::CURRENT_VERSION, set explicitly at
 * analysis-creation time by AiController/TradePackageAiController from this
 * migration forward.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_ai_analyses', function (Blueprint $table) {
            $table->unsignedTinyInteger('telemetry_schema_version')->nullable()->after('workflow');
        });

        Schema::table('trade_package_ai_analyses', function (Blueprint $table) {
            $table->unsignedTinyInteger('telemetry_schema_version')->nullable()->after('workflow');
        });
    }

    public function down(): void
    {
        Schema::table('contract_ai_analyses', function (Blueprint $table) {
            $table->dropColumn('telemetry_schema_version');
        });

        Schema::table('trade_package_ai_analyses', function (Blueprint $table) {
            $table->dropColumn('telemetry_schema_version');
        });
    }
};
