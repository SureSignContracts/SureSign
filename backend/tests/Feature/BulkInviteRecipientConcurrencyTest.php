<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * P2 Security Remediation (Bulk Invite Email-Volume Abuse) — the genuine
 * multi-process concurrency proof for the Cache::lock()-serialized
 * composite recipient reservation in
 * UserController::reserveBulkInviteRecipients().
 *
 * Deliberately NOT run against the default test suite's cache store —
 * phpunit.xml forces CACHE_STORE=array, which is purely in-process PHP
 * memory and invisible across separate PHP processes (the same class of
 * problem AiAnalysisConcurrencyMysqlTest found with SQLite :memory:). This
 * test instead uses the LOCAL development Redis instance — the same real
 * mechanism production actually depends on for this exact feature
 * (CACHE_STORE=redis, REDIS_CLIENT=phpredis in docker-compose.prod.yml) —
 * on a dedicated, isolated logical database index that this test verifies
 * is NOT the app's own configured default/cache database before doing
 * anything. Skips cleanly with an explicit reason if that guard fails or
 * Redis is unreachable — never silently passes.
 *
 * Never uses FLUSHALL/FLUSHDB. Only ever touches keys under the fixed
 * "suresign:test:bulk-invite-concurrency:" prefix, cleaned up individually
 * before and after each test.
 */
class BulkInviteRecipientConcurrencyTest extends TestCase
{
    private const TEST_REDIS_DB = 15;
    private const KEY_PREFIX = 'suresign:test:bulk-invite-concurrency:';

    // Independent host allowlist — deliberately NOT trusting whatever
    // config('database.redis.default.host') happens to resolve to on its
    // own. 'redis' is the docker-compose service name (resolved via
    // Docker's internal DNS when this suite runs inside suresign_backend);
    // 127.0.0.1/localhost cover running directly against a host-mapped
    // port. Anything else — any real hostname or IP, including a
    // production address — is refused by construction (deny-by-default),
    // never accepted.
    private const ALLOWED_TEST_HOSTS = ['redis', '127.0.0.1', 'localhost'];

    // Set on the parent process's own environment right before spawning
    // each child (see spawnChild() below) — an explicit, deliberate
    // "this process was launched BY the test harness" signal, independent
    // of and in addition to the host/DB-index checks, so a stray/copied
    // invocation of the helper script outside this test can never proceed
    // by accident.
    private const TEST_MODE_ENV_VAR = 'SURESIGN_BULK_INVITE_TEST_MODE';

    protected function setUp(): void
    {
        parent::setUp();

        $host = (string) config('database.redis.default.host');
        if (!in_array($host, self::ALLOWED_TEST_HOSTS, true)) {
            $this->markTestSkipped(
                "Refusing to run — the configured Redis host ('{$host}') is not on the "
                . 'explicit test-only allowlist. This is a fail-safe guard: never widen it '
                . 'to accept an arbitrary/production hostname.'
            );
        }

        $realDefaultDb = (int) config('database.redis.default.database', 0);
        $realCacheDb = (int) config('database.redis.cache.database', 1);

        if (self::TEST_REDIS_DB === $realDefaultDb || self::TEST_REDIS_DB === $realCacheDb) {
            $this->markTestSkipped(
                'Refusing to run — the chosen dedicated test Redis database index ('
                . self::TEST_REDIS_DB . ') collides with this app\'s own configured '
                . 'default/cache database. This is a fail-safe guard, never loosen it.'
            );
        }

        config(['database.redis.bulk_invite_test' => [
            'host'     => $host,
            'password' => config('database.redis.default.password'),
            'port'     => config('database.redis.default.port'),
            'database' => self::TEST_REDIS_DB,
        ]]);
        config(['cache.stores.bulk_invite_test' => [
            'driver'     => 'redis',
            'connection' => 'bulk_invite_test',
        ]]);

        try {
            Cache::store('bulk_invite_test')->get('connectivity-probe');
        } catch (\Throwable $e) {
            $this->markTestSkipped('This test requires a reachable local Redis instance: ' . $e->getMessage());
        }

        $this->cleanTestKeys();
    }

    protected function tearDown(): void
    {
        $this->cleanTestKeys();
        parent::tearDown();
    }

    /**
     * Targeted cleanup of ONLY this test's own keys — never FLUSHDB/FLUSHALL.
     */
    private function cleanTestKeys(): void
    {
        // phpredis' KEYS command returns the full raw stored key name
        // (including the client's own OPT_PREFIX, e.g. "suresign-database-"
        // — confirmed by direct inspection: KEYS does not strip it the way
        // single-key commands like GET do), but DEL re-applies OPT_PREFIX
        // to whatever key it's given — so passing KEYS' own return value
        // straight into DEL double-prefixes it and silently deletes
        // nothing (verified directly: del() returned 0 until this fix).
        // Strip the OPT_PREFIX back off before calling del().
        $connection = Cache::store('bulk_invite_test')->getRedis()->connection('bulk_invite_test');
        $optPrefix = (string) config('database.redis.options.prefix', '');
        $keys = $connection->keys('*bulk-invite-concurrency*');
        foreach ($keys as $key) {
            $stripped = str_starts_with($key, $optPrefix) ? substr($key, strlen($optPrefix)) : $key;
            $connection->del($stripped);
        }
    }

    /**
     * Spawns the helper with the explicit test-mode env signal set — the
     * helper refuses to run without it, regardless of what arguments are
     * passed, so a stray/copied invocation outside this test class can
     * never proceed.
     */
    private function spawnChild(int $eligibleCount, int $operatorId, &$pipes)
    {
        $script = base_path('tests/redis_concurrency_helpers/attempt_reservation.php');

        // putenv() on THIS process before spawning — proc_open() with a
        // null $env (rather than an explicit array) inherits the current
        // process's environment automatically, avoiding having to hand-
        // reconstruct $_ENV/$_SERVER as a flat string=>string array.
        putenv(self::TEST_MODE_ENV_VAR . '=1');

        return proc_open(
            ['php', $script, (string) self::TEST_REDIS_DB, (string) $eligibleCount, (string) $operatorId],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
    }

    public function test_two_concurrent_reservations_exceeding_the_limit_result_in_exactly_one_acceptance(): void
    {
        $operatorId = 900001;
        // Fixed hourly limit inside the helper is 100 — two simultaneous
        // requests for 100 each must result in exactly one accepted.
        $processA = $this->spawnChild(100, $operatorId, $pipesA);
        $processB = $this->spawnChild(100, $operatorId, $pipesB);

        $stdoutA = stream_get_contents($pipesA[1]); $stderrA = stream_get_contents($pipesA[2]);
        $stdoutB = stream_get_contents($pipesB[1]); $stderrB = stream_get_contents($pipesB[2]);
        foreach ([$pipesA[1], $pipesA[2], $pipesB[1], $pipesB[2]] as $p) { fclose($p); }
        proc_close($processA); proc_close($processB);

        $resultA = json_decode($stdoutA, true);
        $resultB = json_decode($stdoutB, true);
        $this->assertIsArray($resultA, "Process A non-JSON output. stdout={$stdoutA} stderr={$stderrA}");
        $this->assertIsArray($resultB, "Process B non-JSON output. stdout={$stdoutB} stderr={$stderrB}");
        $this->assertNull($resultA['error']);
        $this->assertNull($resultB['error']);

        $acceptedCount = (int) $resultA['accepted'] + (int) $resultB['accepted'];
        $this->assertSame(1, $acceptedCount, 'Exactly one of the two contending 100-unit reservations must be accepted, never both, never neither.');

        $finalCount = (int) Cache::store('bulk_invite_test')->get(self::KEY_PREFIX . "operator:{$operatorId}:hour", 0);
        $this->assertSame(100, $finalCount, 'Final counter must reflect exactly the one accepted reservation — never 200, never negative.');
    }

    public function test_two_concurrent_reservations_within_the_limit_are_both_accepted(): void
    {
        $operatorId = 900002;

        // 50 + 50 = 100, exactly at the helper's fixed 100 limit — both must fit.
        $processA = $this->spawnChild(50, $operatorId, $pipesA);
        $processB = $this->spawnChild(50, $operatorId, $pipesB);

        $stdoutA = stream_get_contents($pipesA[1]); $stderrA = stream_get_contents($pipesA[2]);
        $stdoutB = stream_get_contents($pipesB[1]); $stderrB = stream_get_contents($pipesB[2]);
        foreach ([$pipesA[1], $pipesA[2], $pipesB[1], $pipesB[2]] as $p) { fclose($p); }
        proc_close($processA); proc_close($processB);

        $resultA = json_decode($stdoutA, true);
        $resultB = json_decode($stdoutB, true);
        $this->assertIsArray($resultA, "Process A non-JSON output. stdout={$stdoutA} stderr={$stderrA}");
        $this->assertIsArray($resultB, "Process B non-JSON output. stdout={$stdoutB} stderr={$stderrB}");
        $this->assertNull($resultA['error']);
        $this->assertNull($resultB['error']);

        $this->assertTrue($resultA['accepted'], 'Both requests fit under the limit and must both be accepted.');
        $this->assertTrue($resultB['accepted']);

        $finalCount = (int) Cache::store('bulk_invite_test')->get(self::KEY_PREFIX . "operator:{$operatorId}:hour", 0);
        $this->assertSame(100, $finalCount, 'Final counter must reflect both accepted reservations summed correctly.');
    }
}
