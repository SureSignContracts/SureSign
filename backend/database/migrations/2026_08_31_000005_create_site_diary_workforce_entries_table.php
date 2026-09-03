<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Friday Pack Realignment, R1C — Workforce. Owned by SiteDiary (capture
 * once, reuse in Friday Pack), not by Friday Pack itself — mirrors R1B's
 * "Site Report evidence, discovered later by Friday Pack" shape. One row
 * per trade/role recorded against a single Site Report.
 *
 * `SiteDiary::$workers_on_site` remains the authoritative OVERALL daily
 * headcount — this table is a SUPPLEMENTARY, optional breakdown, never a
 * replacement (see App\Services\FridayPack\FridayPackWorkforceService).
 *
 * No individual Worker model, no employee identities, no payroll/HR
 * system — `trade_or_role` is free text (ToolboxTalk::$trade_or_subcontractor's
 * existing precedent; no real trade/role taxonomy exists elsewhere in this
 * codebase — TradePackageCatalogueService is package-scope, not
 * worker-role, a confirmed non-fit).
 *
 * `project_id`/`organization_id` are denormalized, matching every other
 * Project-owned table in this codebase (FridayPackPhotoSelection,
 * FileUpload, ContractRisk, etc.) — direct tenant scoping, not a join
 * through site_diary_id, per CLAUDE.md's P0 Security Remediation
 * precedent. `site_diary_id` cascades on delete (a Site Report's workforce
 * breakdown has no meaning without it); `project_id`/`organization_id`
 * follow the same cascade convention every other Project/Organisation-owned
 * table here already uses.
 *
 * Duplicate-role protection (R1C amendment 1): a real, empirically-verified
 * MySQL query (`utf8mb4_0900_ai_ci`, this database's actual collation —
 * confirmed case-insensitive via a live INSERT/duplicate-key test, not
 * assumed) proves `UNIQUE(site_diary_id, trade_or_role)` alone already
 * rejects "labourer" as a duplicate of "Labourer" at the DB level — no
 * second normalized-name column is needed. Application-level trimming
 * still happens at the boundary (see SiteDiaryController) so the exact
 * stored value is never accidentally padded before this constraint sees
 * it.
 *
 * Explicit short MySQL-safe index names, verified below the 64-character
 * limit — this table's own auto-generated unique-index name
 * (`site_diary_workforce_entries_site_diary_id_trade_or_role_unique`,
 * 66 characters) would have repeated the exact R1B.3 defect; both
 * identifiers here are named explicitly from the start.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_diary_workforce_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_diary_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('trade_or_role');
            $table->unsignedInteger('operative_count');
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique(['site_diary_id', 'trade_or_role'], 'sdwe_diary_role_unique');
            $table->index(['site_diary_id', 'sort_order'], 'sdwe_diary_sort_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_diary_workforce_entries');
    }
};
