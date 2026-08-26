<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use PDO;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Remove & Detach Concurrency Hardening — the genuine multi-connection
 * concurrency proof for the withOrganizationLock() fix in
 * UserController::removeAndDetach() / removeUserWithinOrganizationLock().
 *
 * Same rationale and mechanism as AiAnalysisConcurrencyMysqlTest (P2 AI
 * Analysis TOCTOU) — read that file's docblock for the full reasoning this
 * one reuses verbatim: SQLite has no row-level locking, so this is
 * deliberately NOT run against the default SQLite suite, and is skipped
 * entirely (with an explicit reason, never a silent pass) whenever the
 * dedicated, isolated "suresign_concurrency_test" MySQL schema isn't
 * reachable. Execution A (this test process) locks the organisation row
 * FIRST, in-process; Execution B (tests/mysql_concurrency_helpers/
 * attempt_client_removal.php) is a genuinely separate PHP process that
 * measures its own real lock-wait time as direct evidence of contention,
 * not merely sequential ordering.
 *
 * Proves the narrower invariant the approved clarification specifies: a
 * Remove & Detach decision must be authoritative against a FRESH,
 * serialized view of remaining Client users at the moment it is allowed to
 * commit — never that an organisation can't end up with zero Clients via
 * normal Remove at all (that remains allowed, per current semantics).
 */
class UserRemovalConcurrencyMysqlTest extends TestCase
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
                . "is currently configured for '{$connectionConfig['database']}'. Never point "
                . 'MYSQL_CONCURRENCY_DATABASE at production, the shared dev database, or any other schema.'
            );
        }

        config(['database.connections.' . self::CONNECTION => $connectionConfig]);

        try {
            \DB::connection(self::CONNECTION)->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped(
                'This test requires MySQL/InnoDB to verify real row-level lock contention — '
                . 'the dedicated local test schema ("' . self::SAFE_TEST_DATABASE . '") was not '
                . 'reachable: ' . $e->getMessage()
            );
        }

        $pdo = \DB::connection(self::CONNECTION)->getPdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['personal_access_tokens', 'model_has_roles', 'users', 'organizations', 'roles'] as $table) {
            $pdo->exec("TRUNCATE TABLE {$table}");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    private function connectionArgs(): array
    {
        $cfg = config('database.connections.' . self::CONNECTION);

        return [$cfg['host'], $cfg['port'], $cfg['database'], $cfg['username'], $cfg['password']];
    }

    private function makeOrgWithClients(string $suffix, int $clientCount): array
    {
        $org = Organization::on(self::CONNECTION)->create(['name' => "Org {$suffix}", 'slug' => "org-{$suffix}"]);
        $role = Role::on(self::CONNECTION)->firstOrCreate(['name' => 'Client', 'guard_name' => 'web']);

        $clients = [];
        for ($i = 0; $i < $clientCount; $i++) {
            $client = User::on(self::CONNECTION)->create([
                'organization_id' => $org->id,
                'name'            => "Client {$suffix}-{$i}",
                'email'           => "client-{$suffix}-{$i}@example.test",
                'password'        => bcrypt('password'),
                'is_active'       => true,
            ]);
            $client->setConnection(self::CONNECTION);
            $client->roles()->attach($role->id, ['model_type' => User::class]);
            $clients[] = $client;
        }

        return [$org, $clients];
    }

    private function spawn(string $mode, int $organizationId, int $targetUserId, bool $confirm): array
    {
        [$host, $port, $database, $username, $password] = $this->connectionArgs();
        $script = base_path('tests/mysql_concurrency_helpers/attempt_client_removal.php');

        $process = proc_open(
            ['php', $script, $host, (string) $port, $database, $username, $password, $mode, (string) $organizationId, (string) $targetUserId, $confirm ? '1' : '0'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $decoded = json_decode($stdout, true);
        $this->assertIsArray($decoded, "attempt_client_removal.php produced non-JSON output. stdout={$stdout} stderr={$stderr}");

        return $decoded;
    }

    private function isSoftDeleted(int $userId): bool
    {
        $row = \DB::connection(self::CONNECTION)->table('users')->where('id', $userId)->first();

        return $row !== null && $row->deleted_at !== null;
    }

    private function remainingNonDeletedClients(int $organizationId): int
    {
        return \DB::connection(self::CONNECTION)->table('users')
            ->join('model_has_roles', 'model_has_roles.model_id', '=', 'users.id')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('users.organization_id', $organizationId)
            ->where('roles.name', 'Client')
            ->whereNull('users.deleted_at')
            ->count();
    }

    // ── Detach + Detach: the exact race the audit found ──────────────────

    public function test_two_concurrent_unconfirmed_detaches_of_the_only_two_clients_never_both_succeed(): void
    {
        [$org, $clients] = $this->makeOrgWithClients('dd1', 2);
        [$clientA, $clientB] = $clients;

        $pdo = \DB::connection(self::CONNECTION)->getPdo();
        $pdo->beginTransaction();

        // Execution A (this process) acquires the organisation lock FIRST,
        // in-process — proven by this query having already returned before
        // B is spawned below.
        $lockStmt = $pdo->prepare('SELECT id FROM organizations WHERE id = :id FOR UPDATE');
        $lockStmt->execute(['id' => $org->id]);
        $lockStmt->fetch();

        // Spawn Execution B now, detaching the OTHER client — it must block
        // on the SAME organisation row until A commits or rolls back.
        [$host, $port, $database, $username, $password] = $this->connectionArgs();
        $script = base_path('tests/mysql_concurrency_helpers/attempt_client_removal.php');
        $process = proc_open(
            ['php', $script, $host, (string) $port, $database, $username, $password, 'detach', (string) $org->id, (string) $clientB->id, '0'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        usleep((int) (self::HOLD_SECONDS * 1_000_000));

        // A's own authoritative re-check (mirrors removeAndDetach()'s
        // in-lock last-Client count) — must see B still present (B is
        // still blocked on the lock A holds).
        $othersForA = $pdo->query(
            "SELECT COUNT(*) AS c FROM users u
             JOIN model_has_roles mhr ON mhr.model_id = u.id
             JOIN roles r ON r.id = mhr.role_id AND r.name = 'Client'
             WHERE u.organization_id = {$org->id} AND u.id != {$clientA->id} AND u.deleted_at IS NULL"
        )->fetch(PDO::FETCH_ASSOC)['c'];
        $this->assertSame(1, (int) $othersForA, 'A must still see B as a non-deleted Client while holding the lock.');

        // A proceeds — detaches A, since B (unresolved, still blocked) is
        // correctly seen as still present.
        $pdo->prepare('DELETE FROM personal_access_tokens WHERE tokenable_id = :id')->execute(['id' => $clientA->id]);
        $pdo->prepare('UPDATE users SET organization_id = NULL, deleted_at = NOW() WHERE id = :id')->execute(['id' => $clientA->id]);
        $pdo->commit(); // releases the lock — B should now unblock

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $bResult = json_decode($stdout, true);
        $this->assertIsArray($bResult, "attempt_client_removal.php produced non-JSON output. stdout={$stdout} stderr={$stderr}");
        $this->assertNull($bResult['error']);

        // Direct evidence B was genuinely blocked at the MySQL layer.
        $this->assertGreaterThanOrEqual(
            self::HOLD_SECONDS * 0.5,
            $bResult['wait_seconds'],
            "Execution B's measured lock-wait time must reflect genuinely blocking on A's held row lock."
        );

        // The actual invariant under test: B must be REJECTED (last-Client
        // confirmation required), not silently succeed — since by the time
        // B's own lock-protected count runs (after A committed), A is
        // already soft-deleted/detached, leaving B as the last Client.
        $this->assertTrue($bResult['rejected'], "B must be rejected as the last Client once it observes A's committed detach.");
        $this->assertFalse($bResult['mutated'], 'A rejected detach must perform zero mutation.');
        $this->assertSame(0, $bResult['remaining_clients'], "B's in-lock count must see zero OTHER non-deleted Clients once A has committed.");

        // Final authoritative state: exactly one non-deleted Client remains
        // (B) — the race the audit found (both silently succeeding, zero
        // remaining) never happens.
        $this->assertTrue($this->isSoftDeleted($clientA->id));
        $this->assertFalse($this->isSoftDeleted($clientB->id));
        $this->assertSame(1, $this->remainingNonDeletedClients($org->id));
    }

    // ── Detach + Normal Remove, Order A: normal remove commits first ─────

    public function test_normal_remove_committing_first_makes_the_subsequent_detach_require_confirmation(): void
    {
        [$org, $clients] = $this->makeOrgWithClients('order-a', 2);
        [$clientA, $clientB] = $clients;

        // B is normal-removed and commits FIRST, fully sequentially — no
        // race needed to prove this direction, only that the later detach
        // recomputes against B's already-committed removal rather than a
        // snapshot taken before it.
        $bResult = $this->spawn('normal_remove', $org->id, $clientB->id, false);
        $this->assertNull($bResult['error']);
        $this->assertTrue($bResult['mutated']);
        $this->assertTrue($this->isSoftDeleted($clientB->id));
        // Normal remove semantics unchanged: organization_id preserved.
        $orgIdAfterNormalRemove = \DB::connection(self::CONNECTION)->table('users')->where('id', $clientB->id)->value('organization_id');
        $this->assertSame($org->id, $orgIdAfterNormalRemove);

        // Detach A now — must see B already gone and require confirmation.
        $aResult = $this->spawn('detach', $org->id, $clientA->id, false);
        $this->assertNull($aResult['error']);
        $this->assertTrue($aResult['rejected'], 'Detach must require last-Client confirmation once the sibling Client is already removed.');
        $this->assertFalse($aResult['mutated']);
        $this->assertSame(0, $aResult['remaining_clients']);

        // Confirmed detach then proceeds normally.
        $aConfirmed = $this->spawn('detach', $org->id, $clientA->id, true);
        $this->assertTrue($aConfirmed['mutated']);
        $this->assertTrue($this->isSoftDeleted($clientA->id));
        $orgIdAfterDetach = \DB::connection(self::CONNECTION)->table('users')->where('id', $clientA->id)->value('organization_id');
        $this->assertNull($orgIdAfterDetach);
    }

    // ── Detach + Normal Remove, Order B: detach commits first ────────────

    public function test_detach_committing_first_while_a_sibling_exists_then_a_later_normal_remove_is_allowed_to_reach_zero(): void
    {
        [$org, $clients] = $this->makeOrgWithClients('order-b', 2);
        [$clientA, $clientB] = $clients;

        // Detach A first — B genuinely exists at this point, so no
        // confirmation is required; this is not a stale decision.
        $aResult = $this->spawn('detach', $org->id, $clientA->id, false);
        $this->assertNull($aResult['error']);
        $this->assertFalse($aResult['rejected']);
        $this->assertTrue($aResult['mutated']);
        $this->assertSame(1, $aResult['remaining_clients'], 'A must correctly see B as a genuine remaining Client at the time A commits.');

        // Later, B is normal-removed under its own existing, unchanged
        // semantics — this is allowed to bring the organisation to zero
        // non-deleted Clients. This is NOT a guard bypass: the earlier
        // detach decision was correct against the state that existed when
        // IT committed; the later normal remove causing zero Clients is a
        // separate, already-permitted event, per the approved invariant
        // clarification.
        $bResult = $this->spawn('normal_remove', $org->id, $clientB->id, false);
        $this->assertNull($bResult['error']);
        $this->assertTrue($bResult['mutated']);

        $this->assertTrue($this->isSoftDeleted($clientA->id));
        $this->assertTrue($this->isSoftDeleted($clientB->id));
        $this->assertSame(0, $this->remainingNonDeletedClients($org->id));
    }
}
