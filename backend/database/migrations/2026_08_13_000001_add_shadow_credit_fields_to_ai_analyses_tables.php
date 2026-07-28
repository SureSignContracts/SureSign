<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G4C.3BC — shadow-accounting fields on the existing analysis tables,
 * NOT a new reporting surface and NOT a change to the G4C.3A ledger. The
 * analysis row remains the single execution-level source of truth for these
 * facts, exactly like every other telemetry column already here
 * (provider_called, failure_category, etc.).
 *
 * shadow_enforcement_result is explicitly three-valued —
 * sufficient|insufficient|unresolved — never left null-meaning-unresolved,
 * so reporting can distinguish "balance was fine", "balance would not have
 * covered this", and "no shadow policy is configured yet" without guessing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_ai_analyses', function (Blueprint $table) {
            $table->decimal('credit_reservation_amount', 10, 2)->nullable()->after('failure_category');
            $table->string('shadow_enforcement_result', 20)->nullable()->after('credit_reservation_amount');
        });

        Schema::table('trade_package_ai_analyses', function (Blueprint $table) {
            $table->decimal('credit_reservation_amount', 10, 2)->nullable()->after('failure_category');
            $table->string('shadow_enforcement_result', 20)->nullable()->after('credit_reservation_amount');
        });
    }

    public function down(): void
    {
        Schema::table('contract_ai_analyses', function (Blueprint $table) {
            $table->dropColumn(['credit_reservation_amount', 'shadow_enforcement_result']);
        });

        Schema::table('trade_package_ai_analyses', function (Blueprint $table) {
            $table->dropColumn(['credit_reservation_amount', 'shadow_enforcement_result']);
        });
    }
};
