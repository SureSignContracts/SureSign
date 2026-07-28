<?php

/**
 * PHPUnit test bootstrap — runs before ANY test class is loaded.
 *
 * Root cause of the 2026-07-10 and 2026-07-22 incidents (both wiped the real
 * local `suresign` MySQL database): phpunit.xml's <env name="..." value="..."
 * force="true"/> block calls putenv() and sets $_ENV, but does NOT touch
 * $_SERVER. PHP's CLI SAPI populates $_SERVER from the real process/container
 * environment (docker-compose's DB_CONNECTION=mysql) at startup, and
 * Laravel's env()/config() resolution consults $_SERVER — so that stale
 * 'mysql' value silently won every time, despite getenv()/$_ENV correctly
 * showing 'sqlite'. RefreshDatabase-based tests then ran migrate:fresh
 * against the real database.
 *
 * Fix: explicitly set putenv() + $_ENV + $_SERVER here, in a bootstrap file
 * that PHPUnit requires before instantiating any test — guaranteeing the
 * override is visible however Laravel's env() resolution looks it up, and
 * before a single test's setUp() (and therefore RefreshDatabase) can run.
 *
 * This is the single source of truth for the testing environment overrides;
 * phpunit.xml's <php><env> block is kept only as a secondary, redundant
 * declaration for tooling (IDEs, etc.) that reads phpunit.xml directly — do
 * not rely on it alone. Tests\TestCase also hard-aborts the whole process
 * (not just the current test) if these values are ever inconsistent, as a
 * second independent layer against this exact failure mode recurring.
 */

require __DIR__ . '/../vendor/autoload.php';

$overrides = [
    'APP_ENV'                => 'testing',
    'DB_CONNECTION'          => 'sqlite',
    'DB_DATABASE'            => ':memory:',
    'DB_URL'                 => '',
    'CACHE_STORE'            => 'array',
    'SESSION_DRIVER'         => 'array',
    'QUEUE_CONNECTION'       => 'sync',
    'MAIL_MAILER'            => 'array',
    'BROADCAST_CONNECTION'   => 'null',
    'BCRYPT_ROUNDS'          => '4',
    'APP_MAINTENANCE_DRIVER' => 'file',
    'PULSE_ENABLED'          => 'false',
    'TELESCOPE_ENABLED'      => 'false',
    'NIGHTWATCH_ENABLED'     => 'false',
];

foreach ($overrides as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key]    = $value;
    $_SERVER[$key] = $value;
}
