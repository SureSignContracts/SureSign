<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Hard safety net against ever wiping a real database, in two layers:
     *
     * 1. A raw, Laravel-independent check of the actual environment
     *    variables — deliberately performed BEFORE parent::setUp() runs.
     *    parent::setUp() is what boots the app AND (via trait
     *    auto-detection) triggers RefreshDatabase's migrate:fresh; by the
     *    time config() is available to read, it is already too late. A
     *    failure here calls exit(1) — a hard process termination, not a
     *    normal test failure/assertion — so nothing else in this PHP
     *    process, including any later test class, can proceed either.
     * 2. A secondary check of config() after boot, for defense in depth, in
     *    case Laravel's own resolution of the environment ever diverges
     *    from the raw values checked in step 1.
     *
     * Why this exists: phpunit.xml's <env force="true"/> block calls
     * putenv() and sets $_ENV, but never touches $_SERVER — and PHP's CLI
     * SAPI already populates $_SERVER from the real process/container
     * environment (e.g. docker-compose's DB_CONNECTION=mysql) before
     * PHPUnit runs. Laravel's env()/config() resolution consults $_SERVER,
     * so that stale value silently won every time. This exact gap wiped the
     * real local `suresign` database on 2026-07-10 and again on 2026-07-22.
     * The actual fix is tests/bootstrap.php, which sets $_SERVER too, before
     * any test class loads — this class is the backstop for if that fix
     * ever regresses, not a substitute for it.
     */
    protected function setUp(): void
    {
        $this->abortUnlessSafeTestEnvironment('pre-boot', getenv('DB_CONNECTION'), getenv('DB_DATABASE'));

        parent::setUp();

        $this->abortUnlessSafeTestEnvironment(
            'post-boot',
            config('database.default'),
            config('database.connections.sqlite.database')
        );
    }

    private function abortUnlessSafeTestEnvironment(string $stage, mixed $connection, mixed $database): void
    {
        if ($connection === 'sqlite' && $database === ':memory:') {
            return;
        }

        fwrite(STDERR, "\n\n"
            . "ABORTING TEST RUN ({$stage} check) — refusing to risk a real database.\n"
            . 'DB_CONNECTION resolved to ' . var_export($connection, true) . ', DB_DATABASE to ' . var_export($database, true) . ".\n"
            . "Expected 'sqlite' / ':memory:'. RefreshDatabase against anything else runs\n"
            . "migrate:fresh against a real database — see tests/bootstrap.php and phpunit.xml.\n"
            . "Fix the test environment before running tests again. Terminating the whole\n"
            . "process immediately (not just failing this test) so no other test can run\n"
            . "the same risk.\n\n"
        );

        exit(1);
    }
}
