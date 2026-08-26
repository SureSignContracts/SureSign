<?php

namespace Tests\Feature;

use App\Models\User;
use PDO;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Last Active Super Admin Concurrency Hardening — the genuine
 * multi-connection concurrency proof for App\Support\Auth\SuperAdminGuard::
 * guardedMutate()/withLock(). Same rationale and mechanism as
 * AiAnalysisConcurrencyMysqlTest / UserRemovalConcurrencyMysqlTest — read
 * those files' docblocks for the full reasoning reused verbatim here:
 * SQLite has no row-level locking, so this is deliberately NOT run against
 * the default SQLite suite, and is skipped entirely (with an explicit
 * reason, never a silent pass) whenever the dedicated, isolated
 * "suresign_concurrency_test" MySQL schema isn't reachable.
 *
 * Self-Service Account Deletion (AuthController::deleteAccount()) is
 * deliberately NOT exercised here — it is Client-only by product decision
 * and never makes a Super Admin decision at all (see that method's own
 * docblock). This suite proves the invariant for the admin-management
 * mutators that actually own it: UserController::destroy() (via
 * SuperAdminGuard::guardedMutate()) and ::update()'s deactivate branch.
 */
class LastActiveSuperAdminConcurrencyMysqlTest extends TestCase
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

    private function makeSuperAdmins(string $suffix, int $count): array
    {
        $role = Role::on(self::CONNECTION)->firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);

        $admins = [];
        for ($i = 0; $i < $count; $i++) {
            $admin = User::on(self::CONNECTION)->create([
                'organization_id' => null,
                'name'            => "SA {$suffix}-{$i}",
                'email'           => "sa-{$suffix}-{$i}@example.test",
                'password'        => bcrypt('password'),
                'is_active'       => true,
            ]);
            $admin->setConnection(self::CONNECTION);
            $admin->roles()->attach($role->id, ['model_type' => User::class]);
            $admins[] = $admin;
        }

        return $admins;
    }

    private function spawn(string $mode, int $targetUserId): array
    {
        [$host, $port, $database, $username, $password] = $this->connectionArgs();
        $script = base_path('tests/mysql_concurrency_helpers/attempt_super_admin_removal.php');

        $process = proc_open(
            ['php', $script, $host, (string) $port, $database, $username, $password, $mode, (string) $targetUserId],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $decoded = json_decode($stdout, true);
        $this->assertIsArray($decoded, "attempt_super_admin_removal.php produced non-JSON output. stdout={$stdout} stderr={$stderr}");

        return $decoded;
    }

    private function activeSuperAdminCount(): int
    {
        return \DB::connection(self::CONNECTION)->table('users')
            ->join('model_has_roles', 'model_has_roles.model_id', '=', 'users.id')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', 'Super Admin')
            ->where('users.is_active', true)
            ->whereNull('users.banned_at')
            ->whereNull('users.deleted_at')
            ->count();
    }

    // ── Same-operation race: remove + remove ─────────────────────────────

    public function test_two_concurrent_removals_of_the_final_two_super_admins_never_both_succeed(): void
    {
        [$adminA, $adminB] = $this->makeSuperAdmins('rr1', 2);

        $pdo = \DB::connection(self::CONNECTION)->getPdo();
        $pdo->beginTransaction();

        // Execution A (this process) locks the 'Super Admin' role row
        // FIRST, in-process — proven by this query having already
        // returned before B is spawned below.
        $lockStmt = $pdo->prepare("SELECT id FROM roles WHERE name = 'Super Admin' AND guard_name = 'web' FOR UPDATE");
        $lockStmt->execute();
        $lockStmt->fetch();

        // Spawn Execution B now, removing the OTHER admin — it must block
        // on the SAME role row until A commits.
        [$host, $port, $database, $username, $password] = $this->connectionArgs();
        $script = base_path('tests/mysql_concurrency_helpers/attempt_super_admin_removal.php');
        $process = proc_open(
            ['php', $script, $host, (string) $port, $database, $username, $password, 'remove', (string) $adminB->id],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        usleep((int) (self::HOLD_SECONDS * 1_000_000));

        // A's own authoritative re-check (mirrors SuperAdminGuard::
        // isLastActive()) — must see both admins still active (B is still
        // blocked on the lock A holds).
        $countForA = $pdo->query(
            "SELECT COUNT(*) AS c FROM users u
             JOIN model_has_roles mhr ON mhr.model_id = u.id
             JOIN roles r ON r.id = mhr.role_id AND r.name = 'Super Admin'
             WHERE u.is_active = 1 AND u.banned_at IS NULL AND u.deleted_at IS NULL"
        )->fetch(PDO::FETCH_ASSOC)['c'];
        $this->assertSame(2, (int) $countForA, 'A must still see both admins active while holding the lock.');

        // A proceeds — removes admin A, since the count (2) is not <= 1.
        $pdo->prepare('DELETE FROM personal_access_tokens WHERE tokenable_id = :id')->execute(['id' => $adminA->id]);
        $pdo->prepare('UPDATE users SET deleted_at = NOW() WHERE id = :id')->execute(['id' => $adminA->id]);
        $pdo->commit(); // releases the lock — B should now unblock

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $bResult = json_decode($stdout, true);
        $this->assertIsArray($bResult, "attempt_super_admin_removal.php produced non-JSON output. stdout={$stdout} stderr={$stderr}");
        $this->assertNull($bResult['error']);

        // Direct evidence B was genuinely blocked at the MySQL layer.
        $this->assertGreaterThanOrEqual(
            self::HOLD_SECONDS * 0.5,
            $bResult['wait_seconds'],
            "Execution B's measured lock-wait time must reflect genuinely blocking on A's held row lock."
        );

        // The actual invariant under test: B must be REJECTED once it
        // observes A's committed removal (count now 1) — never silently
        // succeed alongside it and leave zero.
        $this->assertTrue($bResult['rejected'], 'B must be rejected as the last active Super Admin once it observes A\'s committed removal.');
        $this->assertFalse($bResult['mutated'], 'A rejected removal must perform zero mutation.');
        $this->assertSame(1, $bResult['active_count_seen']);

        $this->assertSame(1, $this->activeSuperAdminCount(), 'Exactly one active Super Admin must remain.');
        $rowB = \DB::connection(self::CONNECTION)->table('users')->where('id', $adminB->id)->first();
        $this->assertNull($rowB->deleted_at, 'B must remain fully intact — zero mutation on rejection.');
        $this->assertSame(0, \DB::connection(self::CONNECTION)->table('personal_access_tokens')->where('tokenable_id', $adminB->id)->count());
    }

    // ── Mixed operation race: remove + deactivate ────────────────────────

    public function test_concurrent_remove_and_deactivate_of_the_final_two_super_admins_never_both_succeed(): void
    {
        [$adminA, $adminB] = $this->makeSuperAdmins('mixed1', 2);

        $pdo = \DB::connection(self::CONNECTION)->getPdo();
        $pdo->beginTransaction();

        $lockStmt = $pdo->prepare("SELECT id FROM roles WHERE name = 'Super Admin' AND guard_name = 'web' FOR UPDATE");
        $lockStmt->execute();
        $lockStmt->fetch();

        // Execution B attempts to DEACTIVATE admin B concurrently — a
        // different mutation (UserController::update()'s deactivate
        // branch) sharing the exact same lock/guard.
        [$host, $port, $database, $username, $password] = $this->connectionArgs();
        $script = base_path('tests/mysql_concurrency_helpers/attempt_super_admin_removal.php');
        $process = proc_open(
            ['php', $script, $host, (string) $port, $database, $username, $password, 'deactivate', (string) $adminB->id],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        usleep((int) (self::HOLD_SECONDS * 1_000_000));

        // Execution A removes admin A.
        $pdo->prepare('DELETE FROM personal_access_tokens WHERE tokenable_id = :id')->execute(['id' => $adminA->id]);
        $pdo->prepare('UPDATE users SET deleted_at = NOW() WHERE id = :id')->execute(['id' => $adminA->id]);
        $pdo->commit();

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $bResult = json_decode($stdout, true);
        $this->assertIsArray($bResult, "attempt_super_admin_removal.php produced non-JSON output. stdout={$stdout} stderr={$stderr}");
        $this->assertNull($bResult['error']);
        $this->assertGreaterThanOrEqual(self::HOLD_SECONDS * 0.5, $bResult['wait_seconds']);

        // B's deactivation must be rejected once it observes A's committed
        // removal — never both commit and leave zero active Super Admins.
        $this->assertTrue($bResult['rejected']);
        $this->assertFalse($bResult['mutated']);

        $this->assertSame(1, $this->activeSuperAdminCount());
        $rowB = \DB::connection(self::CONNECTION)->table('users')->where('id', $adminB->id)->first();
        $this->assertSame(1, (int) $rowB->is_active, 'B must remain active — zero mutation on rejection.');
    }
}
