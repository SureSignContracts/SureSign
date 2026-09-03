<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PlantItem;
use App\Models\Project;
use App\Models\User;
use PDO;
use Tests\TestCase;

/**
 * Friday Pack Realignment, R1E.2D — the genuine multi-connection
 * concurrency proof for PlantDeploymentService's overlap protection.
 * Mirrors AiAnalysisConcurrencyMysqlTest's exact mechanism and reasoning
 * (see that class's own docblock for the full "why MySQL, not SQLite"
 * explanation) — deliberately NOT run against the default SQLite suite.
 *
 * This test connects to the SAME dedicated, isolated MySQL schema
 * ("suresign_concurrency_test") that harness already uses — never the
 * shared dev database. It is skipped entirely (with an explicit reason,
 * never a silent pass) whenever that schema isn't reachable.
 *
 * Mechanism: this test process ("Execution A") opens a real transaction
 * and acquires `SELECT ... FOR UPDATE` on the parent PlantItem row FIRST,
 * in-process — exactly the lock `PlantDeploymentService` itself takes —
 * then spawns a genuinely separate PHP process ("Execution B",
 * tests/mysql_concurrency_helpers/attempt_plant_deployment.php) proposing
 * an OVERLAPPING period for the SAME plant item. B must block at the real
 * MySQL/InnoDB level until A commits, and must then see A's committed row
 * and correctly refuse to create its own (proving the overlap check is
 * genuinely serialized, not just sequenced). A second scenario proves a
 * DIFFERENT plant item's deployment is never blocked by the first item's
 * lock — the real row-level scoping invariant SQLite cannot prove at all.
 */
class PlantDeploymentConcurrencyMysqlTest extends TestCase
{
    private const CONNECTION = 'mysql_concurrency';
    private const HOLD_SECONDS = 0.3;
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
                . "is currently configured for '{$connectionConfig['database']}'."
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

        $pdo = \DB::connection(self::CONNECTION)->getPdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['plant_deployments', 'plant_items', 'projects', 'users', 'organizations'] as $table) {
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
            'organization_id' => $org->id, 'name' => "User {$suffix}",
            'email' => "user-{$suffix}@example.test", 'password' => bcrypt('password'), 'is_active' => true,
        ]);
        $project = Project::on(self::CONNECTION)->create([
            'organization_id' => $org->id, 'created_by' => $user->id, 'name' => "Project {$suffix}",
        ]);

        return [$org, $user, $project];
    }

    private function makePlantItem(Organization $org, User $user, Project $project, string $suffix): PlantItem
    {
        return PlantItem::on(self::CONNECTION)->create([
            'organization_id' => $org->id, 'project_id' => $project->id, 'created_by' => $user->id,
            'name' => "Crane {$suffix}", 'type' => 'Tower Crane',
        ]);
    }

    private function spawnDeploymentAttempt(int $plantItemId, int $projectId, int $orgId, int $createdBy, string $onSiteFrom, ?string $offSiteAt): array
    {
        [$host, $port, $database, $username, $password] = $this->connectionArgs();
        $script = base_path('tests/mysql_concurrency_helpers/attempt_plant_deployment.php');

        $process = proc_open(
            ['php', $script, $host, (string) $port, $database, $username, $password, (string) $plantItemId, (string) $projectId, (string) $orgId, (string) $createdBy, $onSiteFrom, $offSiteAt ?? ''],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $decoded = json_decode($stdout, true);
        $this->assertIsArray($decoded, "attempt_plant_deployment.php produced non-JSON output. stdout={$stdout} stderr={$stderr}");

        return $decoded;
    }

    // ── Same plant item: genuine InnoDB row-level serialization ──────────

    public function test_two_concurrent_overlapping_deployments_for_the_same_plant_item_result_in_exactly_one_row(): void
    {
        [$org, $user, $project] = $this->makeFixtures('pdc1');
        $plantItem = $this->makePlantItem($org, $user, $project, 'pdc1');

        $pdo = \DB::connection(self::CONNECTION)->getPdo();
        $pdo->beginTransaction();

        // Execution A acquires the lock FIRST, in-process — proven by this
        // query having already returned before B is spawned below.
        $lockStmt = $pdo->prepare('SELECT id FROM plant_items WHERE id = :id FOR UPDATE');
        $lockStmt->execute(['id' => $plantItem->id]);
        $lockStmt->fetch();

        [$host, $port, $database, $username, $password] = $this->connectionArgs();
        $script = base_path('tests/mysql_concurrency_helpers/attempt_plant_deployment.php');
        // B proposes an overlapping period (03 Aug -> open-ended).
        $process = proc_open(
            ['php', $script, $host, (string) $port, $database, $username, $password, (string) $plantItem->id, (string) $project->id, (string) $org->id, (string) $user->id, '2026-08-03', ''],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        usleep((int) (self::HOLD_SECONDS * 1_000_000));

        // A's own authoritative overlap re-check — must see zero existing
        // deployments while holding the lock (B is still blocked).
        $overlapCount = $pdo->query(
            "SELECT COUNT(*) AS c FROM plant_deployments WHERE plant_item_id = {$plantItem->id} AND deleted_at IS NULL"
        )->fetch(PDO::FETCH_ASSOC)['c'];
        $this->assertSame(0, (int) $overlapCount, 'A must see zero existing deployments while holding the lock — B must still be blocked.');

        $insert = $pdo->prepare(
            'INSERT INTO plant_deployments (organization_id, project_id, plant_item_id, created_by, on_site_from, off_site_at, created_at, updated_at)
             VALUES (:orgId, :projectId, :plantItemId, :createdBy, :onSiteFrom, NULL, NOW(), NOW())'
        );
        $insert->execute([
            'orgId' => $org->id, 'projectId' => $project->id, 'plantItemId' => $plantItem->id,
            'createdBy' => $user->id, 'onSiteFrom' => '2026-08-01',
        ]);

        $pdo->commit(); // releases the lock — B should now unblock

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $bResult = json_decode($stdout, true);
        $this->assertIsArray($bResult, "attempt_plant_deployment.php produced non-JSON output. stdout={$stdout} stderr={$stderr}");
        $this->assertNull($bResult['error'], 'Execution B must complete without an exception: ' . ($bResult['error'] ?? ''));

        // Direct evidence B was genuinely blocked at the MySQL layer for a
        // duration consistent with A's hold — not merely sequenced.
        $this->assertGreaterThanOrEqual(
            self::HOLD_SECONDS * 0.5,
            $bResult['wait_seconds'],
            "Execution B's measured lock-wait time must reflect genuinely blocking on the row A held."
        );

        // The actual invariant under test: B must NOT have created an
        // overlapping deployment, since its own in-lock re-check saw A's
        // committed, overlapping row.
        $this->assertFalse($bResult['created'], "Execution B must not create an overlapping deployment once it sees A's committed row.");

        $finalCount = \DB::connection(self::CONNECTION)->table('plant_deployments')
            ->where('plant_item_id', $plantItem->id)->count();
        $this->assertSame(1, $finalCount, 'Exactly one deployment row must exist for the plant item after both executions complete.');
    }

    // ── Different plant items: row-level scoping, never SQLite-provable ──

    public function test_two_different_plant_items_are_not_serialized_by_the_same_lock(): void
    {
        [$org, $user, $project] = $this->makeFixtures('pdd1');
        $itemA = $this->makePlantItem($org, $user, $project, 'pdd1a');
        $itemB = $this->makePlantItem($org, $user, $project, 'pdd1b');

        $pdo = \DB::connection(self::CONNECTION)->getPdo();
        $pdo->beginTransaction();

        // A locks its OWN plant item row only.
        $lockStmt = $pdo->prepare('SELECT id FROM plant_items WHERE id = :id FOR UPDATE');
        $lockStmt->execute(['id' => $itemA->id]);
        $lockStmt->fetch();

        // B targets a DIFFERENT plant item's row — must NOT block, since
        // the lock above is scoped to itemA only.
        $bResult = $this->spawnDeploymentAttempt($itemB->id, $project->id, $org->id, $user->id, '2026-08-03', null);

        $pdo->rollBack();

        $this->assertNull($bResult['error']);
        $this->assertLessThan(
            self::HOLD_SECONDS * 0.5,
            $bResult['wait_seconds'],
            "A different plant item's deployment must not be blocked by an unrelated item's row lock."
        );
        $this->assertTrue($bResult['created'], "The unrelated plant item's deployment must succeed independently.");
    }
}
