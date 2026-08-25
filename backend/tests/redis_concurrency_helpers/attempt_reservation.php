<?php

/**
 * Standalone helper for BulkInviteRecipientConcurrencyTest — a genuinely
 * separate PHP process (spawned via proc_open(), mirroring the identical
 * technique already proven out for AiAnalysisConcurrencyMysqlTest's real
 * multi-connection proof) that exercises the EXACT SAME Cache::lock() +
 * RateLimiter admission algorithm as
 * UserController::reserveBulkInviteRecipients() — reimplemented here
 * rather than invoked via reflection on a private controller method,
 * since this is testing the underlying Laravel primitives' real
 * cross-process safety, not controller wiring.
 *
 * Boots the full Laravel application (not a lightweight raw-connection
 * script) because Cache::lock()/RateLimiter must go through the real
 * phpredis-backed cache store exactly as production does — this is the
 * one thing this test needs to prove, so it must use the real stack.
 *
 * SAFETY GUARD — refuses to run unless ALL of these hold, checked
 * independently of the parent test (this script must never trust a
 * caller's arguments blindly, defense-in-depth against a stray/copied
 * invocation):
 *   - the SURESIGN_BULK_INVITE_TEST_MODE=1 environment variable is set —
 *     an explicit "this was deliberately launched by the test harness"
 *     signal, never present in an ordinary shell/CI invocation;
 *   - the resolved Redis host is on a small hardcoded local/test
 *     allowlist ('redis' — the docker-compose service name, '127.0.0.1',
 *     'localhost') — any other hostname/IP, including a production
 *     address, is refused by construction;
 *   - the target Redis database index is explicitly passed and is NOT the
 *     app's own configured default/cache database index;
 *   - all cache keys touched are confined to the fixed test-only prefix.
 * Never touches FLUSHALL/FLUSHDB. Never reads/prints credentials.
 *
 * Usage:
 *   php attempt_reservation.php <redisDbIndex> <eligibleCount> <operatorId>
 *
 * Emits one line of JSON: {"accepted": bool, "error": string|null}
 */

require __DIR__ . '/../../vendor/autoload.php';

const TEST_KEY_PREFIX = 'suresign:test:bulk-invite-concurrency:';
const TEST_HOUR_LIMIT = 100;
const TEST_DAY_LIMIT = 100000; // effectively unbounded for this probe — only the hourly dimension is under test
const TEST_PLATFORM_LIMIT = 100000; // likewise
const ALLOWED_TEST_HOSTS = ['redis', '127.0.0.1', 'localhost'];
const TEST_MODE_ENV_VAR = 'SURESIGN_BULK_INVITE_TEST_MODE';

[, $redisDbIndexArg, $eligibleCountArg, $operatorIdArg] = $argv;
$redisDbIndex = (int) $redisDbIndexArg;
$eligibleCount = (int) $eligibleCountArg;
$operatorId = (int) $operatorIdArg;

$result = ['accepted' => false, 'error' => null];

try {
    if (getenv(TEST_MODE_ENV_VAR) !== '1') {
        throw new \RuntimeException(
            'Refusing to run — ' . TEST_MODE_ENV_VAR . '=1 was not set. This script must only '
            . 'ever be launched by BulkInviteRecipientConcurrencyTest.'
        );
    }

    $app = require __DIR__ . '/../../bootstrap/app.php';
    $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    $host = (string) config('database.redis.default.host');
    if (!in_array($host, ALLOWED_TEST_HOSTS, true)) {
        throw new \RuntimeException("Refusing to run — Redis host '{$host}' is not on the test-only allowlist.");
    }

    // Fail-safe: never run against the app's own real default/cache Redis
    // database index, whatever it's configured to.
    $realDefaultDb = (int) config('database.redis.default.database', 0);
    $realCacheDb = (int) config('database.redis.cache.database', 1);
    if ($redisDbIndex === $realDefaultDb || $redisDbIndex === $realCacheDb) {
        throw new \RuntimeException('Refusing to run against a non-dedicated Redis database index.');
    }

    config(['database.redis.bulk_invite_test' => [
        'host'     => $host,
        'password' => config('database.redis.default.password'),
        'port'     => config('database.redis.default.port'),
        'database' => $redisDbIndex,
    ]]);
    config(['cache.stores.bulk_invite_test' => [
        'driver'     => 'redis',
        'connection' => 'bulk_invite_test',
    ]]);

    $store = \Illuminate\Support\Facades\Cache::store('bulk_invite_test');
    $limiter = new \Illuminate\Cache\RateLimiter($store);

    $lockKey = TEST_KEY_PREFIX . 'reservation-lock';
    $hourKey = TEST_KEY_PREFIX . "operator:{$operatorId}:hour";
    $dayKey = TEST_KEY_PREFIX . "operator:{$operatorId}:day";
    $platformKey = TEST_KEY_PREFIX . 'platform:hour';

    $accepted = $store->lock($lockKey, 10)->block(5, function () use ($limiter, $hourKey, $dayKey, $platformKey, $eligibleCount) {
        $hourAttempts = $limiter->attempts($hourKey);
        $dayAttempts = $limiter->attempts($dayKey);
        $platformAttempts = $limiter->attempts($platformKey);

        $wouldExceed = ($hourAttempts + $eligibleCount > TEST_HOUR_LIMIT)
            || ($dayAttempts + $eligibleCount > TEST_DAY_LIMIT)
            || ($platformAttempts + $eligibleCount > TEST_PLATFORM_LIMIT);

        if ($wouldExceed) {
            return false;
        }

        $limiter->increment($hourKey, 3600, $eligibleCount);
        $limiter->increment($dayKey, 86400, $eligibleCount);
        $limiter->increment($platformKey, 3600, $eligibleCount);

        return true;
    });

    $result['accepted'] = $accepted;
} catch (\Throwable $e) {
    $result['error'] = $e->getMessage();
}

echo json_encode($result);
