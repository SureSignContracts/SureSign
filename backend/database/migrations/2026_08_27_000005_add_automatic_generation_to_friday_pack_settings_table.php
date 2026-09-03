<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automated Friday Pack, V1E — opt-in scheduled Draft generation settings.
 * Additive only (the friday_pack_settings table itself was already run in
 * this environment) — never rewrites the original V1B migration.
 *
 * `automatic_generation_enabled` defaults false — a project must
 * explicitly opt in; no existing project silently starts generating
 * Friday Packs after this migration runs (V1E production-safety
 * requirement).
 *
 * `generation_hour_local` is an hour-only integer (0-23), not a full
 * time — the scheduler infrastructure runs on an hourly UTC tick (see
 * SendDeadlineReminders' own precedent), so exposing minute-level
 * precision in the UI would promise something the scheduler cannot
 * actually honour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('friday_pack_settings', function (Blueprint $table) {
            $table->boolean('automatic_generation_enabled')->default(false)->after('enabled');
            $table->unsignedTinyInteger('generation_hour_local')->default(15)->after('automatic_generation_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('friday_pack_settings', function (Blueprint $table) {
            $table->dropColumn(['automatic_generation_enabled', 'generation_hour_local']);
        });
    }
};
