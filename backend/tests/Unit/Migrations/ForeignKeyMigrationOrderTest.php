<?php

namespace Tests\Unit\Migrations;

use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

/**
 * G4B.1B — Billing Migration Ordering Repair, regression guard. A static
 * scan across every migration FILE (not a live database), so it catches
 * this whole class of bug on every driver equally, rather than relying on
 * a live SQLite test run — SQLite tolerates a forward-declared foreign key
 * to a not-yet-created table at CREATE TABLE time; MySQL does not
 * (error 1824), and that asymmetry is exactly what let
 * `2026_07_23_143613_create_billing_entitlement_snapshots_table.php`
 * reference `subscriptions` (created three days "later",
 * `2026_07_26_000003_create_subscriptions_table.php`) go undetected until
 * a genuinely fresh MySQL install was attempted.
 *
 * Intentionally a regex-based scan, not a full PHP parser — good enough to
 * catch the two foreign-key declaration styles this codebase actually
 * uses (`->constrained('table')` / `->constrained()` / explicit
 * `->foreign('col')->references('id')->on('table')`), which is the real
 * goal: stop a NEW instance of this class of ordering bug from being
 * introduced, not to perfectly parse arbitrary migration code.
 */
class ForeignKeyMigrationOrderTest extends TestCase
{
    /**
     * Known, already-repaired exceptions: this migration still contains
     * the literal text `->constrained('subscriptions')`/`->constrained('pricing_plans')`,
     * but only executes either inside a `driver !== 'mysql'` guard (safe —
     * SQLite tolerates the forward reference; MySQL never takes this
     * branch, and gets both constraints later via
     * 2026_07_26_000011_add_deferred_foreign_keys_to_billing_entitlement_snapshots_table.php
     * once `subscriptions`/`pricing_plans` actually exist). A static regex
     * scan cannot see the conditional, so it must be told about these two
     * already-reviewed cases explicitly rather than silently skipping
     * them.
     *
     * @var array<int, array{file: string, table: string}>
     */
    private const KNOWN_SAFE_EXCEPTIONS = [
        ['file' => '2026_07_23_143613_create_billing_entitlement_snapshots_table.php', 'table' => 'subscriptions'],
        ['file' => '2026_07_23_143613_create_billing_entitlement_snapshots_table.php', 'table' => 'pricing_plans'],
    ];

    public function test_no_migration_declares_a_foreign_key_to_a_table_created_later(): void
    {
        $files = $this->sortedMigrationFiles();
        $creationOrder = $this->tableCreationOrder($files);
        $violations = [];

        foreach ($files as $index => $file) {
            foreach ($this->foreignKeyReferences(file_get_contents($file)) as $referencedTable) {
                if (!isset($creationOrder[$referencedTable])) {
                    continue; // Table not created by any migration scanned (e.g. a permission-package table) — nothing to compare.
                }

                if ($creationOrder[$referencedTable] <= $index) {
                    continue; // Already exists by this migration's turn — fine, including same-file self-reference.
                }

                if ($this->isKnownSafeException(basename($file), $referencedTable)) {
                    continue;
                }

                $violations[] = basename($file) . ' references "' . $referencedTable . '", which is not created until ' . basename($files[$creationOrder[$referencedTable]]);
            }
        }

        $this->assertSame([], $violations, "Migration(s) declare a foreign key to a table created later in migration order — this fails on MySQL (error 1824) even though SQLite silently tolerates it:\n" . implode("\n", $violations));
    }

    /**
     * @return string[] absolute paths, in Laravel's own execution order
     *   (filename sort — the same order `php artisan migrate` uses).
     */
    private function sortedMigrationFiles(): array
    {
        $files = glob(dirname(__DIR__, 3) . '/database/migrations/*.php');
        sort($files);

        return $files;
    }

    /**
     * @param string[] $files
     * @return array<string, int> table name => index of the migration that first creates it
     */
    private function tableCreationOrder(array $files): array
    {
        $order = [];

        foreach ($files as $index => $file) {
            if (preg_match_all('/Schema::create\(\s*[\'"]([a-zA-Z0-9_]+)[\'"]/', file_get_contents($file), $matches)) {
                foreach ($matches[1] as $table) {
                    $order[$table] ??= $index; // first creation only — a table is never created twice
                }
            }
        }

        return $order;
    }

    /**
     * @return string[] table names this migration's content declares a
     *   foreign key against, via either declaration style this codebase
     *   uses.
     */
    private function foreignKeyReferences(string $content): array
    {
        $tables = [];

        // Explicit: ->constrained('table')
        if (preg_match_all('/->constrained\(\s*[\'"]([a-zA-Z0-9_]+)[\'"]\s*\)/', $content, $matches)) {
            array_push($tables, ...$matches[1]);
        }

        // Implicit: $table->foreignId('x_id')->constrained() — table name
        // inferred from the column name, matching Laravel's own convention.
        if (preg_match_all('/foreignId\(\s*[\'"]([a-zA-Z0-9_]+)_id[\'"]\s*\)(?:(?!;).)*?->constrained\(\s*\)/s', $content, $matches)) {
            foreach ($matches[1] as $columnPrefix) {
                $tables[] = Str::plural($columnPrefix);
            }
        }

        // Explicit: ->foreign('col')->references('id')->on('table')
        if (preg_match_all('/->foreign\(\s*[\'"][a-zA-Z0-9_]+[\'"]\s*\)\s*->references\(\s*[\'"]id[\'"]\s*\)\s*->on\(\s*[\'"]([a-zA-Z0-9_]+)[\'"]\s*\)/', $content, $matches)) {
            array_push($tables, ...$matches[1]);
        }

        return $tables;
    }

    private function isKnownSafeException(string $filename, string $table): bool
    {
        foreach (self::KNOWN_SAFE_EXCEPTIONS as $exception) {
            if ($exception['file'] === $filename && $exception['table'] === $table) {
                return true;
            }
        }

        return false;
    }
}
