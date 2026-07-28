<?php

namespace Tests\Unit\Demo;

use App\Support\Demo\DemoDatabaseGuard;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use Tests\TestCase;

/**
 * Regression guard for the exact failure mode demo:reset/demo:seed must
 * never hit: DEMO_DB_DATABASE resolving to the application's own real
 * database. demo:reset runs `migrate:fresh --database=demo` — if that ever
 * resolved to the real database, it would drop every real table.
 */
class DemoDatabaseGuardTest extends TestCase
{
    public function test_passes_when_demo_and_primary_connections_are_genuinely_separate(): void
    {
        Config::set('database.default', 'mysql');
        Config::set('database.connections.mysql', [
            'driver' => 'mysql', 'host' => 'mysql', 'port' => '3306',
            'database' => 'suresign', 'username' => 'root', 'password' => '',
        ]);
        Config::set('demo.connection', 'demo');
        Config::set('database.connections.demo', [
            'driver' => 'mysql', 'host' => 'mysql', 'port' => '3306',
            'database' => 'suresign_demo', 'username' => 'root', 'password' => '',
        ]);

        DemoDatabaseGuard::assertIsolatedFromPrimary();
        $this->addToAssertionCount(1);
    }

    public function test_refuses_when_demo_database_name_equals_the_primary_database_name(): void
    {
        Config::set('database.default', 'mysql');
        Config::set('database.connections.mysql', [
            'driver' => 'mysql', 'host' => 'mysql', 'port' => '3306',
            'database' => 'suresign', 'username' => 'root', 'password' => '',
        ]);
        Config::set('demo.connection', 'demo');
        Config::set('database.connections.demo', [
            // Misconfiguration: DEMO_DB_DATABASE left equal to DB_DATABASE.
            'driver' => 'mysql', 'host' => 'mysql', 'port' => '3306',
            'database' => 'suresign', 'username' => 'root', 'password' => '',
        ]);

        $this->expectException(RuntimeException::class);

        DemoDatabaseGuard::assertIsolatedFromPrimary();
    }

    public function test_refuses_when_the_demo_connection_name_itself_is_the_default_connection(): void
    {
        Config::set('database.default', 'mysql');
        Config::set('demo.connection', 'mysql');

        $this->expectException(RuntimeException::class);

        DemoDatabaseGuard::assertIsolatedFromPrimary();
    }

    public function test_same_database_name_on_different_hosts_is_not_treated_as_a_collision(): void
    {
        Config::set('database.default', 'mysql');
        Config::set('database.connections.mysql', [
            'driver' => 'mysql', 'host' => 'prod-db.internal', 'port' => '3306',
            'database' => 'suresign', 'username' => 'root', 'password' => '',
        ]);
        Config::set('demo.connection', 'demo');
        Config::set('database.connections.demo', [
            'driver' => 'mysql', 'host' => 'demo-db.internal', 'port' => '3306',
            'database' => 'suresign', 'username' => 'root', 'password' => '',
        ]);

        DemoDatabaseGuard::assertIsolatedFromPrimary();
        $this->addToAssertionCount(1);
    }

    public function test_sqlite_connections_are_identified_by_database_path_only(): void
    {
        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite', [
            'driver' => 'sqlite', 'database' => database_path('database.sqlite'),
        ]);
        Config::set('demo.connection', 'demo');
        Config::set('database.connections.demo', [
            'driver' => 'mysql', 'host' => 'mysql', 'port' => '3306',
            'database' => 'suresign_demo', 'username' => 'root', 'password' => '',
        ]);

        DemoDatabaseGuard::assertIsolatedFromPrimary();
        $this->addToAssertionCount(1);
    }
}
