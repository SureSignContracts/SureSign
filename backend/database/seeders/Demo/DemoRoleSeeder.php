<?php

namespace Database\Seeders\Demo;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The demo environment lives on its own database connection (config/demo.php),
 * so it starts with an empty spatie/permission schema even though the tables
 * exist — roles and permissions are per-connection data, not shared with the
 * real platform database. This seeder intentionally mirrors the three-role
 * model (Super Admin, Admin, Client) and permission set defined in
 * database/seeders/DatabaseSeeder.php so demo users behave identically to
 * real ones. If that role/permission model changes, update both places —
 * this is a deliberate, documented duplication (a shared connection is not
 * an option here; see config/demo.php's isolation rationale).
 */
class DemoRoleSeeder extends Seeder
{
    public function run(): void
    {
        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $client = Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']);

        $permissions = [
            'manage organizations', 'manage users',
            'manage projects', 'view projects',
            'manage contracts', 'view contracts',
            'manage rfis', 'view rfis',
            'manage variations', 'view variations',
            'manage payment applications', 'view payment applications',
            'manage documents', 'view documents',
            'manage workflows', 'view workflows',
            'use ai', 'view reports', 'manage reports',
            'view audit logs', 'manage branding',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // Only Client is needed for demo personas, but its permission set
        // must match the real platform's Client role exactly so the demo
        // reflects genuine access, not a hand-picked subset.
        $client->syncPermissions([
            'view projects', 'view contracts', 'view rfis',
            'view variations', 'view payment applications',
            'view documents', 'view reports',
        ]);
    }
}
