<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * G4B.1B — Billing Migration Ordering Repair. Adds, on MySQL only, the two
 * foreign keys `2026_07_23_143613_create_billing_entitlement_snapshots_table.php`
 * no longer declares inline for that driver:
 *   - `subscription_id` → `subscriptions.id` (subscriptions created by
 *     2026_07_26_000003_create_subscriptions_table.php)
 *   - `pricing_plan_id` → `pricing_plans.id` (pricing_plans created by
 *     2026_07_25_000002_create_pricing_plans_table.php)
 * Both referenced tables exist by this migration's own timestamp.
 *
 * Also backfills the `(subscription_id, effective_from)` composite index
 * for any environment that ran the ORIGINAL migration under its old
 * auto-generated name ("billing_entitlement_snapshots_subscription_id_effective_from_index",
 * 68 characters — over MySQL's 64-character identifier limit, error 1059)
 * and therefore never actually got this index at all — confirmed to be
 * the case in this project's own local MySQL dev database (`SHOW INDEX`
 * shows no such index there). The original migration now uses a short
 * explicit name and creates this index successfully on any FRESH install,
 * so this backfill only ever does something on an environment carrying
 * that historical gap.
 *
 * Idempotent by design, not just by convention: an environment that
 * already applied the ORIGINAL (pre-repair) version of the 2026_07_23
 * migration — confirmed to be the case in this project's own local MySQL
 * dev database, where migrations are applied incrementally as they're
 * introduced rather than strictly by timestamp from empty, so the
 * FK-ordering defect never actually manifested there — already has both
 * foreign keys. This migration checks each one before adding it.
 * Production's migration-application state is unknown from this session;
 * these idempotent checks are what make that unknown safe rather than
 * something that needed to be confirmed before writing this file.
 *
 * No-ops entirely on SQLite: the original migration already declares both
 * constraints (and the index, always under its short name) inline there
 * (SQLite tolerates the forward reference at CREATE TABLE time and has no
 * identifier length limit), so there is nothing left for this migration
 * to add.
 */
return new class extends Migration
{
    private const TABLE = 'billing_entitlement_snapshots';
    private const INDEX_NAME = 'billing_entitlement_snapshots_sub_effective_idx';

    /** @var array<int, array{column: string, references_table: string, constraint: string, on_delete: string}> */
    private const DEFERRED_FOREIGN_KEYS = [
        ['column' => 'subscription_id', 'references_table' => 'subscriptions', 'constraint' => 'billing_entitlement_snapshots_subscription_id_foreign', 'on_delete' => 'cascade'],
        ['column' => 'pricing_plan_id', 'references_table' => 'pricing_plans', 'constraint' => 'billing_entitlement_snapshots_pricing_plan_id_foreign', 'on_delete' => 'set null'],
    ];

    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::DEFERRED_FOREIGN_KEYS as $fk) {
            if ($this->constraintExists($fk['constraint'])) {
                continue;
            }

            Schema::table(self::TABLE, function (Blueprint $table) use ($fk) {
                $foreign = $table->foreign($fk['column'])->references('id')->on($fk['references_table']);
                $fk['on_delete'] === 'cascade' ? $foreign->cascadeOnDelete() : $foreign->nullOnDelete();
            });
        }

        if (!$this->indexExists(self::INDEX_NAME)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->index(['subscription_id', 'effective_from'], self::INDEX_NAME);
            });
        }
    }

    /**
     * Known, documented rollback limitation: in an environment where
     * up() was a no-op for a given constraint (it already existed from
     * the pre-repair original migration), down() still drops it — rolling
     * back "this migration" in that specific environment therefore also
     * undoes the older migration's own effect, not only this one's. This
     * is the standard, accepted tradeoff for an idempotent guard rather
     * than a hidden inconsistency: predictable and documented, matching
     * "was this constraint ever added, by whichever migration" rather
     * than tracking exactly which migration is responsible for it.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::DEFERRED_FOREIGN_KEYS as $fk) {
            if (!$this->constraintExists($fk['constraint'])) {
                continue;
            }

            Schema::table(self::TABLE, function (Blueprint $table) use ($fk) {
                $table->dropForeign($fk['constraint']);
            });
        }

        // Deliberately does NOT drop the index in down() — unlike the two
        // foreign keys above, an environment missing this index has a real
        // historical GAP (not merely "this migration's own no-op"), and
        // this repair's whole point is to close that gap; reversing it on
        // rollback would silently reopen a pre-existing production-
        // readiness issue rather than undo something this migration itself
        // introduced fresh.
    }

    private function constraintExists(string $constraintName): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS cnt FROM information_schema.TABLE_CONSTRAINTS '
            . 'WHERE table_schema = DATABASE() AND table_name = ? AND constraint_name = ? AND constraint_type = ?',
            [self::TABLE, $constraintName, 'FOREIGN KEY'],
        );

        return $row->cnt > 0;
    }

    private function indexExists(string $indexName): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS cnt FROM information_schema.STATISTICS '
            . 'WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [self::TABLE, $indexName],
        );

        return $row->cnt > 0;
    }
};
