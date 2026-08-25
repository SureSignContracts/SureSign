<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Organization;
use App\Models\Project;
use App\Models\TradePackage;
use App\Models\User;
use PDO;
use Tests\TestCase;

/**
 * P2 AI Analysis TOCTOU / Duplicate Execution Race — the genuine
 * multi-connection concurrency proof for the lockForUpdate() fix in
 * AiController::startAnalysis() / TradePackageAiController::startAnalysis().
 *
 * Deliberately NOT run against the default SQLite (:memory:) suite —
 * SQLite has no row-level locking (it locks the whole database/file for
 * writes), so a test asserting "two different resources don't block each
 * other" would be meaningless there, and even the same-resource case would
 * only prove SQLite's own coarser serialization, not the InnoDB row-level
 * guarantee the real fix relies on. See project-context.md's AI Analysis
 * TOCTOU entry for the full reasoning.
 *
 * This test connects to a DEDICATED, ISOLATED MySQL schema
 * ("suresign_concurrency_test") on the local dev MySQL server — never the
 * shared dev database, and never anything reachable outside this local
 * machine's own Docker network. It is skipped entirely (with an explicit
 * reason, never a silent pass) whenever that schema isn't reachable —
 * e.g. in an environment with no local MySQL, or CI without this schema
 * provisioned.
 *
 * Mechanism: this test process ("Execution A") opens a real transaction and
 * acquires SELECT ... FOR UPDATE on the parent Contract/TradePackage row
 * FIRST, in-process — so by the time it spawns a genuinely separate PHP
 * process ("Execution B", tests/mysql_concurrency_helpers/attempt_claim.php)
 * via proc_open(), A's lock is already held (proven by the prior query
 * having already returned). B attempts the identical claim logic against
 * the SAME row and must block at the real MySQL/InnoDB level until A
 * commits. B measures and reports its own wait duration — direct,
 * independent evidence it was actually blocked, not merely sequenced —
 * and reports whether it created a row. A's own held-lock duration is a
 * short, deliberate, EVIDENCED sleep (only after independently confirming
 * the lock is held), never used as the sole proof of concurrency by itself.
 */
class AiAnalysisConcurrencyMysqlTest extends TestCase
{
    private const CONNECTION = 'mysql_concurrency';
    private const HOLD_SECONDS = 0.3;

    /**
     * The ONLY database this harness is ever allowed to touch. Checked
     * BEFORE any connection attempt or destructive operation (the
     * TRUNCATEs below, and the real row locks/inserts in every test) —
     * regardless of what MYSQL_CONCURRENCY_* env vars happen to resolve
     * to (a stray CI env var, an accidental override, copy-paste from a
     * different env file, etc). This is a fail-safe guard, not a
     * feature-behavior assertion — it must never be loosened to a
     * pattern/prefix match, only this exact literal name.
     */
    private const SAFE_TEST_DATABASE = 'suresign_concurrency_test';

    protected function setUp(): void
    {
        parent::setUp();

        $connectionConfig = [
            'driver'   => 'mysql',
            'host'     => env('MYSQL_CONCURRENCY_HOST', '127.0.0.1'),
            'port'     => env('MYSQL_CONCURRENCY_PORT', '3307'),
            'database' => env('MYSQL_CONCURRENCY_DATABASE', self::SAFE_TEST_DATABASE),
            'username' => env('MYSQL_CONCURRENCY_USERNAME', 'suresign'),
            'password' => env('MYSQL_CONCURRENCY_PASSWORD', 'SureSign@2024!'),
            'charset'  => 'utf8mb4',
        ];

        if ($connectionConfig['database'] !== self::SAFE_TEST_DATABASE) {
            $this->markTestSkipped(
                "Refusing to run — this harness performs destructive TRUNCATEs and only ever "
                . "runs against the dedicated test schema '" . self::SAFE_TEST_DATABASE . "', but "
                . "is currently configured for '{$connectionConfig['database']}'. This is a "
                . 'fail-safe guard: never point MYSQL_CONCURRENCY_DATABASE at production, the '
                . 'shared dev database, or any other schema.'
            );
        }

        config(['database.connections.' . self::CONNECTION => $connectionConfig]);

        try {
            \DB::connection(self::CONNECTION)->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped(
                'This test requires MySQL/InnoDB to verify real row-level lock '
                . 'contention — the dedicated local test schema ("' . self::SAFE_TEST_DATABASE . '") '
                . 'was not reachable: ' . $e->getMessage()
            );
        }

        // Fresh, isolated fixture state per test — this schema exists solely
        // for this test class, so a full truncate is safe and fast.
        $pdo = \DB::connection(self::CONNECTION)->getPdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['contract_ai_analyses', 'trade_package_ai_analyses', 'contracts', 'trade_packages', 'projects', 'users', 'organizations'] as $table) {
            $pdo->exec("TRUNCATE TABLE {$table}");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    private function connectionArgs(): array
    {
        $cfg = config('database.connections.' . self::CONNECTION);

        return [$cfg['host'], $cfg['port'], $cfg['database'], $cfg['username'], $cfg['password']];
    }

    private function makeFixtures(string $suffix): array
    {
        $org = Organization::on(self::CONNECTION)->create(['name' => "Org {$suffix}", 'slug' => "org-{$suffix}"]);
        $user = User::on(self::CONNECTION)->create([
            'organization_id' => $org->id,
            'name'            => "User {$suffix}",
            'email'           => "user-{$suffix}@example.test",
            'password'        => bcrypt('password'),
            'is_active'       => true,
        ]);
        $project = Project::on(self::CONNECTION)->create([
            'organization_id' => $org->id,
            'created_by'      => $user->id,
            'name'            => "Project {$suffix}",
        ]);

        return [$org, $user, $project];
    }

    private function makeContract(Organization $org, User $user, Project $project, string $suffix): Contract
    {
        return Contract::on(self::CONNECTION)->create([
            'project_id'      => $project->id,
            'organization_id' => $org->id,
            'created_by'      => $user->id,
            'type'            => 'main_contract',
            'title'           => "Contract {$suffix}",
        ]);
    }

    private function makeTradePackage(Organization $org, User $user, Project $project, string $suffix): TradePackage
    {
        return TradePackage::on(self::CONNECTION)->create([
            'organization_id' => $org->id,
            'project_id'      => $project->id,
            'name'            => "Package {$suffix}",
            'slug'            => "package-{$suffix}-" . uniqid(),
            'created_by'      => $user->id,
        ]);
    }

    /**
     * Spawns Execution B as a genuinely separate PHP process and returns
     * its decoded JSON result once it completes. Blocking by design — the
     * calling test only proceeds once B has actually finished, which for
     * the same-row case only happens after A releases its lock.
     */
    private function spawnClaimAttempt(string $mode, int $parentId, int $createdBy): array
    {
        [$host, $port, $database, $username, $password] = $this->connectionArgs();
        $script = base_path('tests/mysql_concurrency_helpers/attempt_claim.php');

        $process = proc_open(
            ['php', $script, $host, (string) $port, $database, $username, $password, $mode, (string) $parentId, (string) $createdBy],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $decoded = json_decode($stdout, true);
        $this->assertIsArray($decoded, "attempt_claim.php produced non-JSON output. stdout={$stdout} stderr={$stderr}");

        return $decoded;
    }

    // ── Same resource: genuine InnoDB row-level serialization ────────────────

    public function test_two_concurrent_starts_for_the_same_contract_result_in_exactly_one_active_analysis(): void
    {
        [$org, $user, $project] = $this->makeFixtures('mcc1');
        $contract = $this->makeContract($org, $user, $project, 'mcc1');

        $pdo = \DB::connection(self::CONNECTION)->getPdo();
        $pdo->beginTransaction();

        // Execution A acquires the lock FIRST, in-process — proven by this
        // query having already returned before B is spawned below.
        $lockStmt = $pdo->prepare('SELECT id FROM contracts WHERE id = :id FOR UPDATE');
        $lockStmt->execute(['id' => $contract->id]);
        $lockStmt->fetch();

        // Spawn Execution B now — it targets the SAME contract row and must
        // block at the real MySQL level until A commits below.
        $bResultPromise = null;
        $bProcessArgs = ['contract', $contract->id, $user->id];

        // Deliberate, EVIDENCED hold — the lock is already independently
        // confirmed acquired above; this is not the sole proof of
        // concurrency, only the controlled window B's own measured wait is
        // checked against.
        $bStartedAt = microtime(true);
        // Fork B in a way that lets A keep running while B blocks: we launch
        // B via proc_open (non-blocking to start), sleep while A holds the
        // lock, do A's own work, then commit, THEN read B's result — B's
        // process has been running/blocking on the lock this entire time.
        [$host, $port, $database, $username, $password] = $this->connectionArgs();
        $script = base_path('tests/mysql_concurrency_helpers/attempt_claim.php');
        $process = proc_open(
            ['php', $script, $host, (string) $port, $database, $username, $password, 'contract', (string) $contract->id, (string) $user->id],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        usleep((int) (self::HOLD_SECONDS * 1_000_000));

        // A's own authoritative re-check + create, mirroring the real
        // controller — must see zero active analyses (B is still blocked).
        $activeCount = $pdo->query(
            "SELECT COUNT(*) AS c FROM contract_ai_analyses WHERE contract_id = {$contract->id} AND status IN ('pending','processing')"
        )->fetch(PDO::FETCH_ASSOC)['c'];
        $this->assertSame(0, (int) $activeCount, 'A must see zero active analyses while holding the lock — B must still be blocked.');

        $insert = $pdo->prepare(
            "INSERT INTO contract_ai_analyses
                (contract_id, organization_id, project_id, status, created_by, telemetry_schema_version, created_at, updated_at)
             VALUES (:contractId, :orgId, :projectId, 'pending', :createdBy, 2, NOW(), NOW())"
        );
        $insert->execute([
            'contractId' => $contract->id,
            'orgId'      => $org->id,
            'projectId'  => $project->id,
            'createdBy'  => $user->id,
        ]);

        $pdo->commit(); // releases the lock — B should now unblock

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $bResult = json_decode($stdout, true);
        $this->assertIsArray($bResult, "attempt_claim.php produced non-JSON output. stdout={$stdout} stderr={$stderr}");
        $this->assertNull($bResult['error'], 'Execution B must complete without an exception: ' . ($bResult['error'] ?? ''));

        // Direct evidence B was genuinely blocked at the MySQL layer for a
        // duration consistent with A's hold — not merely sequenced.
        $this->assertGreaterThanOrEqual(
            self::HOLD_SECONDS * 0.5,
            $bResult['wait_seconds'],
            'Execution B\'s measured lock-wait time must reflect genuinely blocking on the row A held.'
        );

        // The actual invariant under test: B must NOT have created a
        // second row, since its own in-lock re-check saw A's committed one.
        $this->assertFalse($bResult['created'], 'Execution B must not create a duplicate analysis once it sees A\'s committed row.');

        $finalCount = \DB::connection(self::CONNECTION)->table('contract_ai_analyses')
            ->where('contract_id', $contract->id)->count();
        $this->assertSame(1, $finalCount, 'Exactly one analysis row must exist for the contract after both executions complete.');
    }

    public function test_two_concurrent_starts_for_the_same_trade_package_result_in_exactly_one_active_analysis(): void
    {
        [$org, $user, $project] = $this->makeFixtures('mcp1');
        $tradePackage = $this->makeTradePackage($org, $user, $project, 'mcp1');

        $pdo = \DB::connection(self::CONNECTION)->getPdo();
        $pdo->beginTransaction();

        $lockStmt = $pdo->prepare('SELECT id FROM trade_packages WHERE id = :id FOR UPDATE');
        $lockStmt->execute(['id' => $tradePackage->id]);
        $lockStmt->fetch();

        [$host, $port, $database, $username, $password] = $this->connectionArgs();
        $script = base_path('tests/mysql_concurrency_helpers/attempt_claim.php');
        $process = proc_open(
            ['php', $script, $host, (string) $port, $database, $username, $password, 'package', (string) $tradePackage->id, (string) $user->id],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        usleep((int) (self::HOLD_SECONDS * 1_000_000));

        $activeCount = $pdo->query(
            "SELECT COUNT(*) AS c FROM trade_package_ai_analyses WHERE trade_package_id = {$tradePackage->id} AND status IN ('pending','processing')"
        )->fetch(PDO::FETCH_ASSOC)['c'];
        $this->assertSame(0, (int) $activeCount);

        $insert = $pdo->prepare(
            "INSERT INTO trade_package_ai_analyses
                (trade_package_id, organization_id, project_id, status, created_by, telemetry_schema_version, created_at, updated_at)
             VALUES (:tpId, :orgId, :projectId, 'pending', :createdBy, 2, NOW(), NOW())"
        );
        $insert->execute([
            'tpId'      => $tradePackage->id,
            'orgId'     => $org->id,
            'projectId' => $project->id,
            'createdBy' => $user->id,
        ]);

        $pdo->commit();

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $bResult = json_decode($stdout, true);
        $this->assertIsArray($bResult, "attempt_claim.php produced non-JSON output. stdout={$stdout} stderr={$stderr}");
        $this->assertNull($bResult['error']);
        $this->assertGreaterThanOrEqual(self::HOLD_SECONDS * 0.5, $bResult['wait_seconds']);
        $this->assertFalse($bResult['created']);

        $finalCount = \DB::connection(self::CONNECTION)->table('trade_package_ai_analyses')
            ->where('trade_package_id', $tradePackage->id)->count();
        $this->assertSame(1, $finalCount);
    }

    // ── Different resources: row-level scoping, never SQLite-provable ────────

    public function test_two_different_contracts_are_not_serialized_by_the_same_lock(): void
    {
        [$org, $user, $project] = $this->makeFixtures('mcd1');
        $contractA = $this->makeContract($org, $user, $project, 'mcd1a');
        $contractB = $this->makeContract($org, $user, $project, 'mcd1b');

        $pdo = \DB::connection(self::CONNECTION)->getPdo();
        $pdo->beginTransaction();

        // A locks its OWN row only.
        $lockStmt = $pdo->prepare('SELECT id FROM contracts WHERE id = :id FOR UPDATE');
        $lockStmt->execute(['id' => $contractA->id]);
        $lockStmt->fetch();

        // B targets a DIFFERENT contract's row — must NOT block, since the
        // lock above is scoped to contractA only (the real invariant this
        // architecture depends on, and the one thing SQLite cannot prove at
        // all, since it locks the whole database rather than a single row).
        $bResult = $this->spawnClaimAttempt('contract', $contractB->id, $user->id);

        $pdo->rollBack(); // A never actually claims here — irrelevant to this assertion

        $this->assertNull($bResult['error']);
        $this->assertLessThan(
            self::HOLD_SECONDS * 0.5,
            $bResult['wait_seconds'],
            'A different contract\'s claim must not be blocked by an unrelated contract\'s row lock.'
        );
        $this->assertTrue($bResult['created'], 'The unrelated contract\'s claim must succeed independently.');
    }
}
