<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automated Friday Pack, V1E — distinguishes who/what generated a pack's
 * CURRENT snapshot. Additive/non-destructive: `generated_by` becomes
 * nullable (never rewritten in place for any existing row) and
 * `generation_source` is added with a default of 'manual' — every
 * already-existing FridayPack row (all genuinely manually generated,
 * since V1E is the first phase capable of producing anything else) gets
 * 'manual' automatically via that column default, with zero data loss or
 * rewrite of `generated_by`.
 *
 * No genuine system/automation User actor exists anywhere in this
 * codebase (confirmed — ProjectActivityService::record() requires a
 * real, non-nullable User, and project_activities.user_id is NOT NULL at
 * the schema level) — this nullable `generated_by` + `generation_source`
 * pair is the deliberate fallback the V1E spec itself calls for, not a
 * schema compromise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('friday_packs', function (Blueprint $table) {
            $table->foreignId('generated_by')->nullable()->change();
            $table->string('generation_source')->default('manual')->after('generated_by');
        });
    }

    public function down(): void
    {
        Schema::table('friday_packs', function (Blueprint $table) {
            $table->dropColumn('generation_source');
            // generated_by is deliberately NOT reverted to non-nullable —
            // doing so would either fail outright on any genuinely
            // scheduled-generated row (generated_by IS NULL) or require
            // inventing a fake user id to backfill it, which this phase's
            // own actor policy explicitly forbids. Mirrors
            // 2026_08_17_000002_fix_projects_country_default's own
            // conditional-down precedent for exactly this situation.
        });
    }
};
