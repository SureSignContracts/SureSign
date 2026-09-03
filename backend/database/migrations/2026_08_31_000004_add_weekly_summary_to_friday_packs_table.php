<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Friday Pack Realignment, R1C — Weekly Summary. A genuinely new column,
 * deliberately NOT a reuse of `executive_summary`/`progress_commentary`/
 * `key_concerns`/`next_week_priorities` (schema-1 / possible future Weekly
 * Project Report fields — see App\Support\FridayPack\FridayPackSections'
 * own docblock). `weekly_summary` is the confirmed, human-edited narrative
 * for the authentic schema-2 Friday Pack's Weekly Summary section, seeded
 * from deterministic Site Report source material
 * (App\Services\FridayPack\FridayPackWeeklySummarySourceService) but never
 * auto-composed — see FridayPackController::update().
 *
 * Plain nullable text, matching the exact column type already used for
 * every other manual-commentary field on this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('friday_packs', function (Blueprint $table) {
            $table->text('weekly_summary')->nullable()->after('next_week_priorities');
        });
    }

    public function down(): void
    {
        Schema::table('friday_packs', function (Blueprint $table) {
            $table->dropColumn('weekly_summary');
        });
    }
};
