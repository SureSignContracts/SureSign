<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Schema-only fix for the same class of defect already corrected for
 * `projects.country` (2026_08_17_000002_fix_projects_country_default.php)
 * and `projects.currency` (2026_07_20_000002_fix_projects_currency_default.php):
 * the `organizations` table's `country` column was created NOT NULL with
 * `default('AU')` (2026_01_01_000001_create_organizations_table.php), and
 * no later migration touching this table ever revisited that column
 * (`add_company_details`/`add_currency`/`add_timezone` all only use
 * `country` as an `after()` anchor for a sibling column).
 *
 * Confirmed directly (not assumed) that no application code ever
 * deliberately sets `country` to `'AU'` — `OrganizationController::onboard()`/
 * `onboardCompany()` both validate `country` as `nullable|string|max:100`;
 * when a caller omits it, the key is simply absent from `$validated`, and
 * `Organization::create($validated)` never mentions `country` at all for
 * that insert — the column's own database-level default is the ONLY thing
 * that fills it with `'AU'`. This is a pure schema defect, not an
 * application-level fallback silently mirrored in code (which would have
 * been a different, code-level fix instead).
 *
 * Live-verified against the real database before writing this migration:
 * `organizations.country` is `varchar(255) NOT NULL DEFAULT 'AU'`; of 4
 * existing rows, 2 have `country = 'AU'` and 2 have `country =
 * 'Philippines'` (confirmed via `information_schema.columns` and direct
 * counts) — no row is currently `NULL`, since the column has never
 * permitted it.
 *
 * Deliberately does NOT backfill or normalize the existing `'AU'` rows —
 * mirrors the `projects.country` fix's own reasoning exactly:
 * `organizations.country` is free text, not a fixed dropdown (even after
 * the Global Country / Region Selector work, which only changed how NEW
 * values are entered, never re-validated or rewrote what's already
 * stored — see `CountrySelect`), so a row that genuinely IS an Australian
 * organisation and a row that only ever inherited the old default are
 * indistinguishable from the data alone. Without a safe, provable way to
 * tell them apart, existing data is left exactly as it is — only the
 * schema changes, so a *future* organisation that omits `country` gets
 * genuine `NULL` instead of silently inheriting `'AU'`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('country')->nullable()->default(null)->change();
        });
    }

    /**
     * Restores the exact prior schema contract — NOT NULL, default 'AU' —
     * matching what `2026_01_01_000001_create_organizations_table.php`
     * originally created. Never fabricates a NOT NULL rollback that wasn't
     * the original state.
     *
     * This is only actually safe to do once a genuinely new organisation
     * has been created (after `up()`) with no country supplied — the
     * whole point of this migration — since that row's `country` is now
     * `NULL`, and `NULL` can never satisfy a NOT NULL constraint. Rolling
     * back at that point has exactly two honest options: leave that
     * organisation's `country` as `NULL` (impossible once NOT NULL is
     * reapplied) or invent a value for it. Inventing `'AU'` — or any other
     * country — for a row that was created with NO country selected would
     * be exactly the silent-default bug this migration exists to remove,
     * reintroduced by its own rollback. So this never happens: `down()`
     * checks for any `NULL` `country` row first, and refuses to run at
     * all if one exists, rather than either mutating it or leaving the
     * schema change half-applied. A genuinely empty rollback (no NULL
     * rows recorded yet) remains fully safe and restores the schema
     * exactly.
     */
    public function down(): void
    {
        if (DB::table('organizations')->whereNull('country')->exists()) {
            throw new RuntimeException(
                'Cannot roll back 2026_08_20_000002_fix_organizations_country_default: '
                . 'one or more organizations rows have a NULL country. Restoring the '
                . "original NOT NULL DEFAULT 'AU' constraint would require either leaving "
                . 'those rows in a state that violates the constraint, or inventing a '
                . 'country value for them — both are refused. This migration never '
                . 'mutates organisation data in either direction; resolve those specific '
                . 'rows deliberately (a genuine, explicit country selection by whoever owns '
                . 'that organisation) before attempting this rollback.'
            );
        }

        Schema::table('organizations', function (Blueprint $table) {
            $table->string('country')->nullable(false)->default('AU')->change();
        });
    }
};
