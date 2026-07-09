<?php

namespace Database\Seeders;

use App\Models\BrandingSetting;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Roles — exactly three: Super Admin, Admin, Client
        $superAdmin = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $adminRole  = Role::firstOrCreate(['name' => 'Admin',       'guard_name' => 'web']);
        $client     = Role::firstOrCreate(['name' => 'Client',      'guard_name' => 'web']);

        // Permissions
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

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $superAdmin->syncPermissions(Permission::all());

        $adminRole->syncPermissions([
            'manage projects', 'view projects',
            'manage contracts', 'view contracts',
            'manage rfis', 'view rfis',
            'manage variations', 'view variations',
            'manage payment applications', 'view payment applications',
            'manage documents', 'view documents',
            'manage workflows', 'view workflows',
            'use ai', 'view reports', 'manage reports',
        ]);

        $client->syncPermissions([
            'view projects', 'view contracts', 'view rfis',
            'view variations', 'view payment applications',
            'view documents', 'view reports',
        ]);

        // Default organization
        $org = Organization::firstOrCreate(
            ['slug' => 'suresign'],
            [
                'name'      => 'SureSign Admin',
                'email'     => 'admin@suresign.app',
                'country'   => 'AU',
                'is_active' => true,
            ]
        );

        // Default branding
        BrandingSetting::firstOrCreate(
            ['organization_id' => $org->id],
            [
                'primary_color'        => '#B99566',
                'secondary_color'      => '#1a1a1a',
                'accent_color'         => '#B99566',
                'font_family'          => 'Inter',
                'company_display_name' => 'SureSign',
            ]
        );

        // Super Admin user — password from env, or a generated temporary one
        // that the account is forced to change on first login.
        $superAdminEmail      = env('SEED_SUPER_ADMIN_EMAIL', 'admin@suresign.app');
        $superAdminGenerated  = empty(env('SEED_SUPER_ADMIN_PASSWORD'));
        $superAdminPassword   = env('SEED_SUPER_ADMIN_PASSWORD') ?: Str::random(20);

        $admin = User::firstOrCreate(
            ['email' => $superAdminEmail],
            [
                'organization_id'       => $org->id,
                'name'                  => 'Super Admin',
                'password'              => Hash::make($superAdminPassword),
                'is_active'             => true,
                'job_title'             => 'Platform Administrator',
                'must_change_password'  => true,
            ]
        );
        $admin->assignRole($superAdmin);

        // Graham — org admin
        $grahamEmail      = env('SEED_GRAHAM_EMAIL', 'graham@suresigncontracts.com');
        $grahamGenerated  = empty(env('SEED_GRAHAM_PASSWORD'));
        $grahamPassword   = env('SEED_GRAHAM_PASSWORD') ?: Str::random(20);

        $graham = User::firstOrCreate(
            ['email' => $grahamEmail],
            [
                'organization_id'       => $org->id,
                'name'                  => 'Graham',
                'password'              => Hash::make($grahamPassword),
                'is_active'             => true,
                'job_title'             => 'Director',
                'must_change_password'  => true,
            ]
        );
        $graham->assignRole($adminRole);

        $this->command->info('✓ Seeded: roles, permissions, default org, super admin');

        // Only ever print a password once, right after the account is first
        // created — never on subsequent (idempotent) seed runs.
        if ($admin->wasRecentlyCreated) {
            $this->command->info(
                "  Created {$superAdminEmail}" . ($superAdminGenerated ? " with a temporary password: {$superAdminPassword}" : ' (password set from SEED_SUPER_ADMIN_PASSWORD).')
            );
        }
        if ($graham->wasRecentlyCreated) {
            $this->command->info(
                "  Created {$grahamEmail}" . ($grahamGenerated ? " with a temporary password: {$grahamPassword}" : ' (password set from SEED_GRAHAM_PASSWORD).')
            );
        }

        $this->call([
            PromptCategorySeeder::class,
            PromptTemplateSeeder::class,
        ]);
    }
}
