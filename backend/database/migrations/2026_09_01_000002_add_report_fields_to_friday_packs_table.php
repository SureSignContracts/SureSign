<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Friday Pack Realignment, R1D — Report Information, Site Issues, Look
 * Ahead. A genuine September 1 migration — never back-dated to August 31,
 * and never modifies an already-run Friday Pack migration.
 *
 * `site_issues_summary`/`look_ahead` mirror `weekly_summary`'s exact
 * contract (R1C) — plain nullable `text`, genuinely new fields, never a
 * reuse of `key_concerns`/`next_week_priorities` (schema-1 / possible
 * future Weekly Project Report content).
 *
 * `report_number` is allocated ONCE, at first-time Friday Pack creation
 * only (see App\Services\DocumentNumberService::allocateFridayPackSequence()
 * and App\Services\FridayPack\FridayPackGenerationService) — stable across
 * regeneration/review/approval/delivery. `UNIQUE(project_id, report_number)`
 * is a defence-in-depth DB invariant on top of the existing
 * `document_number_sequences` row-locking primitive this reuses — never a
 * second, independent numbering mechanism. NULL `report_number` values
 * (every pre-R1D row, forever) are unaffected by the unique constraint —
 * standard SQL NULL-exclusion from uniqueness checks applies on both
 * MySQL and SQLite.
 *
 * Explicit short MySQL-safe index name (`friday_packs_project_report_unique`,
 * 34 characters — well under the 64-character limit R1B.3/R1C already
 * established the discipline for) rather than Laravel's own auto-generated
 * name, applied proactively from the start.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('friday_packs', function (Blueprint $table) {
            $table->text('site_issues_summary')->nullable()->after('weekly_summary');
            $table->text('look_ahead')->nullable()->after('site_issues_summary');
            $table->string('report_number')->nullable()->after('look_ahead');

            $table->unique(['project_id', 'report_number'], 'friday_packs_project_report_unique');
        });
    }

    public function down(): void
    {
        Schema::table('friday_packs', function (Blueprint $table) {
            $table->dropUnique('friday_packs_project_report_unique');
            $table->dropColumn(['site_issues_summary', 'look_ahead', 'report_number']);
        });
    }
};
