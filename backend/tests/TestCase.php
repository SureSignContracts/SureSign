<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Hard safety net: refuse to run ANY test against a non-sqlite
     * connection. phpunit.xml's <env> overrides for DB_CONNECTION/DB_DATABASE
     * are NOT reliably honored in every environment (confirmed 2026-07-10 —
     * config('database.default') can still resolve to the container's real
     * 'mysql' connection even when getenv()/$_ENV correctly show 'sqlite',
     * because the config value is resolved before those overrides land).
     * A test using RefreshDatabase against the wrong connection means
     * migrate:fresh runs against a real database and destroys its data.
     * Fail loudly here rather than silently wiping anything ever again.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $connection = config('database.default');

        if ($connection !== 'sqlite') {
            static::fail(
                "Refusing to run tests: database.default is '{$connection}', not 'sqlite'. "
                . 'Running tests in this state risks migrate:fresh wiping a real database. '
                . 'Fix the test environment (DB_CONNECTION/DB_DATABASE) before running tests.'
            );
        }
    }
}
