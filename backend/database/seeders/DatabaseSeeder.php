<?php

namespace Database\Seeders;

use App\Models\BrandingSetting;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Roles — exactly three: Super Admin, Admin, Client
        $superAdmin = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $admin      = Role::firstOrCreate(['name' => 'Admin',       'guard_name' => 'web']);
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

        $admin->syncPermissions([
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

        // Super Admin user
        $admin = User::firstOrCreate(
            ['email' => 'admin@suresign.app'],
            [
                'organization_id' => $org->id,
                'name'            => 'Super Admin',
                'password'        => Hash::make('Admin@2024!'),
                'is_active'       => true,
                'job_title'       => 'Platform Administrator',
            ]
        );
        $admin->assignRole($superAdmin);

        $this->command->info('✓ Seeded: roles, permissions, default org, super admin');
        $this->command->info('  Login: admin@suresign.app / Admin@2024!');

        $this->call([
            PromptCategorySeeder::class,
            PromptTemplateSeeder::class,
        ]);
    }
}
