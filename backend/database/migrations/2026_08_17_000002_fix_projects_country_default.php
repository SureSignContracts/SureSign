<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schema-only fix for the same class of defect
 * 2026_07_20_000002_fix_projects_currency_default.php already fixed for
 * `currency`: the `projects` table's `country` column was created NOT NULL
 * with default('AU') (2026_01_01_000003_create_projects_table.php).
 * Confirmed no code path ever deliberately sets it at creation —
 * `ProjectController::store` doesn't even accept a `country` field, and the
 * Create Project form doesn't collect location fields at all (see
 * project-context.md's Project Organization Role Phase B entry) — so every
 * Project ever created silently got 'AU' from the column default alone.
 *
 * Deliberately does NOT backfill existing 'AU' rows to null, unlike the
 * currency migration this mirrors. Confirmed directly against the real
 * database that every existing row is exactly 'AU' with no other value
 * ever recorded — consistent with default noise, but `country` is a plain
 * free-text field (not a fixed dropdown), so a row where a user genuinely
 * typed the literal string "AU" (rather than "Australia") can't be
 * completely ruled out. Without a safe, provable way to distinguish
 * "never touched" from "deliberately typed AU," existing data is left
 * exactly as it is — only the schema changes, so a *future* row that omits
 * `country` gets genuine NULL instead of silently inheriting 'AU'.
 *
 * The specific problem this was found while fixing was
 * ProjectContractSetupSyncService's Project Location default-selection
 * heuristic ("is this Project's location genuinely blank"). An earlier
 * revision of that service special-cased an existing 'AU' value as
 * presumptively-default for that one heuristic — reviewed and rejected:
 * the same unprovable ambiguity this migration's own docblock describes
 * means the *application* must not reinterpret a stored 'AU' as blank
 * either, not just the database. `projectLocationSuggestion()` now treats
 * any non-null stored `country` (including 'AU') as real, already-present
 * location data — a Project is "blank" only when every one of its five
 * location fields is genuinely null. This migration's schema fix still
 * matters on its own: it's what makes a *future* Project genuinely get
 * NULL instead of silently inheriting 'AU' at creation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('country')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('country')->nullable(false)->default('AU')->change();
        });
    }
};
