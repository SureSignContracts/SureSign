<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recovery migration — 2026_08_17_000001_create_consultancy_slot_reservations_table
 * shipped with an unnamed composite index whose auto-generated name
 * (consultancy_slot_reservations_consultant_user_id_status_expires_at_index,
 * 72 chars) exceeds MySQL's 64-character identifier limit (error 1059).
 *
 * On a fresh (e.g. production) database this fails the CREATE TABLE
 * statement's trailing index-creation step, but the table itself (columns
 * + foreign keys) is still created by that point. If `migrate` is then run
 * a second time, this migration's own `if (Schema::hasTable(...)) return;`
 * guard (written for exactly this class of interrupted-run scenario) sees
 * the table already exists and returns immediately without ever adding
 * the missing indexes — yet the migration is still recorded as run. That
 * original migration can never be re-run to finish the job (Laravel skips
 * anything already in the `migrations` table), so this is a separate,
 * additive fix.
 *
 * Deliberately checks each index individually before adding it (via
 * information_schema — no doctrine/dbal dependency) rather than assuming
 * exactly which one(s) are missing, so this is safe to run regardless of
 * precisely where the original CREATE TABLE statement failed.
 */
return new class extends Migration
{
    public function up(): void
    {
        // This recovery migration exists solely for a MySQL-specific bug
        // (a >64-char auto-generated index name, MySQL error 1059) — it
        // cannot occur on any other driver, so the original migration's
        // index is already correctly present there. The addIndexIfMissing()
        // helper below uses a raw information_schema.STATISTICS query with
        // no portable equivalent on SQLite, which otherwise breaks the
        // entire test suite (phpunit.xml hard-forces sqlite :memory:).
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        if (! Schema::hasTable('consultancy_slot_reservations')) {
            // Table itself missing entirely (e.g. a fresh environment that
            // never hit the original bug at all, or already fully cleaned
            // up) — nothing for this recovery migration to do; the fixed
            // original migration handles table creation from scratch.
            return;
        }

        $this->addIndexIfMissing(
            'consultancy_slot_reservations',
            'csr_consultant_status_expires_idx',
            fn (Blueprint $table) => $table->index(['consultant_user_id', 'status', 'expires_at'], 'csr_consultant_status_expires_idx'),
        );

        $this->addIndexIfMissing(
            'consultancy_slot_reservations',
            'consultancy_slot_reservations_starts_at_ends_at_index',
            fn (Blueprint $table) => $table->index(['starts_at', 'ends_at']),
        );
    }

    /**
     * Deliberately a no-op. Confirmed live: MySQL refuses to drop
     * `csr_consultant_status_expires_idx` ("Cannot drop index ...: needed
     * in a foreign key constraint") — it's the index InnoDB is actually
     * using to satisfy the `consultant_user_id` foreign key, not merely a
     * redundant one. Rolling back a recovery migration that only ever
     * adds missing indexes isn't something this deployment needs anyway.
     */
    public function down(): void
    {
    }

    private function addIndexIfMissing(string $table, string $indexName, callable $add): void
    {
        $exists = DB::selectOne(
            'SELECT COUNT(*) AS cnt FROM information_schema.STATISTICS
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [$table, $indexName],
        )->cnt > 0;

        if (! $exists) {
            Schema::table($table, $add);
        }
    }
};
