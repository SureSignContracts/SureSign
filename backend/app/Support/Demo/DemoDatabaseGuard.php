<?php

namespace App\Support\Demo;

use RuntimeException;

/**
 * Hard safety net for demo:seed / demo:reset: refuses to run at all if the
 * 'demo' connection (config/demo.php) resolves to the exact same physical
 * database as the application's own default connection. Both commands
 * already document that they only ever touch the 'demo' connection — this
 * guard is what actually makes that true even under a misconfigured .env
 * (e.g. DEMO_DB_DATABASE left equal to DB_DATABASE, or a copy-pasted
 * production .env that never set the DEMO_DB_* overrides at all), rather
 * than relying solely on the two env vars happening to differ.
 *
 * demo:reset runs `migrate:fresh --database=demo`, which DROPS every table
 * on whatever this resolves to — this check is what stands between a
 * misconfigured environment and a dropped production database.
 */
class DemoDatabaseGuard
{
    public static function assertIsolatedFromPrimary(): void
    {
        $demoConnectionName = config('demo.connection', 'demo');
        $defaultConnectionName = config('database.default');

        if ($demoConnectionName === $defaultConnectionName) {
            throw new RuntimeException(
                "Refusing to run: the demo connection ('{$demoConnectionName}') is configured as this "
                . "application's own default database connection. Set config('demo.connection') / the demo "
                . 'commands to use a genuinely separate connection before running demo:seed or demo:reset.'
            );
        }

        $demo = config("database.connections.{$demoConnectionName}");
        $primary = config("database.connections.{$defaultConnectionName}");

        if ($demo === null || $primary === null) {
            throw new RuntimeException(
                "Refusing to run: could not resolve both the '{$demoConnectionName}' and "
                . "'{$defaultConnectionName}' database connections to compare them."
            );
        }

        if (self::identity($demo) === self::identity($primary)) {
            throw new RuntimeException(
                "Refusing to run: the '{$demoConnectionName}' connection resolves to the exact same "
                . "database as this application's '{$defaultConnectionName}' connection "
                . "(driver/host/port/database all match). This almost always means DEMO_DB_DATABASE "
                . 'is unset or accidentally equal to DB_DATABASE. Fix the DEMO_DB_* environment '
                . 'variables so the demo environment points at a genuinely separate database before '
                . 'running demo:seed or demo:reset — running against the real database would '
                . '(for demo:reset) DROP every real table.'
            );
        }
    }

    /**
     * File-based drivers (sqlite) are identified purely by their 'database'
     * path — 'host'/'port' are meaningless for them and must never be
     * compared. Network drivers (mysql/mariadb) are identified by
     * driver+host+port+database together, since the same database name on
     * two different servers is not actually the same database.
     */
    private static function identity(array $connection): string
    {
        $driver = $connection['driver'] ?? '';

        if ($driver === 'sqlite') {
            return "sqlite::{$connection['database']}";
        }

        return implode('::', [
            $driver,
            $connection['host'] ?? '',
            $connection['port'] ?? '',
            $connection['database'] ?? '',
        ]);
    }
}
