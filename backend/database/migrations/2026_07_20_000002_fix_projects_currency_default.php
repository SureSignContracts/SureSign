<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Corrects a schema defect found in the currency-inheritance audit: the
 * `projects` table's `currency` column was created NOT NULL with
 * default('AUD') (2026_01_01_000003_create_projects_table.php). No form,
 * controller, factory, or seeder in the codebase ever set a project's
 * currency deliberately — `ProjectController::store`/`storeForCompany`/
 * `update` never accepted a `currency` field at all — so every project ever
 * created silently got 'AUD' from the column default alone, regardless of
 * the project's actual country or the organisation's real currency. This is
 * the exact, sole source of "AUD" appearing on projects that never had a
 * currency deliberately chosen for them.
 *
 * This migration:
 *   1. Makes the column nullable with no default, across every driver —
 *      `->nullable()->change()` needs no doctrine/dbal package on Laravel 11
 *      (its schema grammars compile ALTER natively for MySQL/SQLite/Postgres/
 *      SQL Server; verified directly against this project's own Laravel
 *      version, not assumed). A future insert that omits `currency` now
 *      genuinely gets NULL, which CurrencyService::resolveCode() treats as
 *      "inherit from organisation, then platform, then GBP" — not 'AUD'.
 *   2. Backfills existing rows: any project currently stored as 'AUD' was
 *      never a deliberate choice (confirmed above), so it is reset to NULL,
 *      correctly making it inherit instead of being permanently stuck on a
 *      value nobody chose. This is a data correction, not a currency
 *      conversion: no monetary amount is touched, only the currency label
 *      on rows where that label was never authoritative to begin with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('currency', 3)->nullable()->default(null)->change();
        });

        DB::table('projects')->where('currency', 'AUD')->update(['currency' => null]);
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('currency', 3)->nullable(false)->default('AUD')->change();
        });
    }
};
