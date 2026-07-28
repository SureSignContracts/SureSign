<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suresign_settings', function (Blueprint $table) {
            // The single, authoritative AI Credit operating mode — replaces the
            // never-released boolean ai_credit_enforcement_enabled column
            // outright (that migration was introduced and superseded within
            // the same unreleased window, so there is no split-brain state to
            // migrate away from). One of App\Support\AI\AiCreditOperatingMode::ALL
            // ('disabled'/'shadow'/'enforced') — see that class for what each
            // mode actually does. Defaults to 'shadow' (the string literal is
            // deliberate, not a reference to the class constant — migrations
            // must never depend on application code that could change shape
            // after this migration ships): the existing shadow accounting
            // behaviour is preserved exactly as-is after this migration;
            // nothing about today's behaviour changes.
            $table->string('ai_credit_operating_mode', 20)
                ->default('shadow')
                ->after('ai_effort');
        });
    }

    public function down(): void
    {
        Schema::table('suresign_settings', function (Blueprint $table) {
            $table->dropColumn('ai_credit_operating_mode');
        });
    }
};
