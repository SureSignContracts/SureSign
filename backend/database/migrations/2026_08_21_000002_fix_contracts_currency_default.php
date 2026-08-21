<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contract AI Workflow Convergence, Phase 2A — corrects the exact same class
 * of schema defect `2026_07_20_000002_fix_projects_currency_default.php`
 * already fixed for `projects.currency`: `contracts.currency` was created
 * NOT NULL with default('AUD') (2026_01_01_000005_create_contracts_table.php)
 * and never touched since.
 *
 * This is a pre-existing defect, not something the Contract AI Workflow
 * Convergence phase introduced — discovery confirmed the OLD, unconverged
 * AiContractWizard upload path never persisted AI-extracted currency onto a
 * newly-created stub Contract either, because its final save was
 * `PUT /contracts/{id}` (`ContractController::update()`), which has never
 * accepted a `currency` field at all. The new, converged path surfaced the
 * defect (by finally routing through the real, deterministic
 * `ContractIntelligenceSyncService`, whose "only write an empty field"
 * guard treats the DB-default 'AUD' as already-set) rather than creating
 * it.
 *
 * Unlike the `projects.currency` fix, this migration does NOT backfill
 * existing rows. `ProjectController::store()`/`update()` never accepted a
 * `currency` field at all, so every existing 'AUD' Project row was provably
 * default-noise. `ContractController::store()` DOES accept an explicit
 * `currency` field — so an existing Contract's 'AUD' cannot be proven pure
 * noise with the same certainty (a genuine Australian contract, or a value
 * set via the "Use Existing Analysis as Template" / manual-creation paths,
 * both of which always submit an explicit currency, cannot be ruled out).
 * Discovery found exactly 3 existing Contract rows, all 'AUD' — left
 * completely untouched here, mirroring the more conservative precedent
 * `2026_08_17_000002_fix_projects_country_default.php` already established
 * for exactly this kind of unprovable-provenance case.
 *
 * `->nullable()->change()` needs no doctrine/dbal package on this Laravel
 * version (verified previously for the identical `projects.currency` fix,
 * confirmed unchanged here) — a future insert that omits `currency` now
 * genuinely gets NULL, which `ContractIntelligenceSyncService`'s own
 * `$should = fn($current) => $overwrite || empty($current)` guard already
 * correctly treats as "safe to populate from confirmed AI data" — no
 * change to that service was needed or made.
 *
 * `down()` — rollback-safety check (2026-08-21): after this migration has
 * been live, genuinely NULL Contract rows can legitimately exist (a stub
 * Contract with no AI confirmation yet). Verified directly against a real
 * MySQL row in this state: `ALTER TABLE contracts MODIFY currency
 * varchar(3) NOT NULL DEFAULT 'AUD'` fails outright with
 * `SQLSTATE[22004]: 1138 Invalid use of NULL value` while any such row
 * exists — confirmed by deliberately reproducing it, not assumed. `down()`
 * is explicitly restoring the historical invariant that every Contract
 * necessarily had a persisted currency, so it is the one place a `NULL` →
 * `'AUD'` normalization is appropriate — scoped with `whereNull('currency')`
 * so it can only ever touch rows that are genuinely NULL. It never touches
 * a non-null value: `'USD'`/`'GBP'`/`'EUR'`/a genuine `'AUD'` all pass
 * through untouched, verified directly against real rows in each of those
 * states, not just NULL. `up()` itself performs no data mutation at all —
 * this asymmetry is deliberate, not an oversight: `up()` must never
 * rewrite historical data (existing 'AUD' rows have unprovable provenance,
 * per this migration's own reasoning above), while `down()`'s
 * normalization is required simply to let the column physically become
 * NOT NULL again — a mechanical schema-restoration necessity, not a
 * currency-correctness decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('currency', 3)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        // Required before the column can become NOT NULL again — a NULL
        // Contract currency is only possible because up() made the column
        // nullable; scoped to NULL rows only, never touches any non-null
        // value (see this migration's own docblock).
        DB::table('contracts')->whereNull('currency')->update(['currency' => 'AUD']);

        Schema::table('contracts', function (Blueprint $table) {
            $table->string('currency', 3)->nullable(false)->default('AUD')->change();
        });
    }
};
