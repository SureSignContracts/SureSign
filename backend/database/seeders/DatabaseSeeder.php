<?php

namespace Database\Seeders;

use App\Models\SuresignSetting;
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

        // Super Admin and Admin are platform-wide (per this app's role model —
        // only Client is org-scoped), so neither is assigned to an
        // organization. No dummy org/branding row is created for them.

        // Super Admin user — password from env, or a generated temporary one
        // that the account is forced to change on first login.
        $superAdminEmail      = env('SEED_SUPER_ADMIN_EMAIL', 'admin@suresign.app');
        $superAdminGenerated  = empty(env('SEED_SUPER_ADMIN_PASSWORD'));
        $superAdminPassword   = env('SEED_SUPER_ADMIN_PASSWORD') ?: Str::random(20);

        $admin = User::firstOrCreate(
            ['email' => $superAdminEmail],
            [
                'organization_id'       => null,
                'name'                  => 'Super Admin',
                'password'              => Hash::make($superAdminPassword),
                'is_active'             => true,
                'job_title'             => 'Platform Administrator',
                'must_change_password'  => true,
            ]
        );
        $admin->assignRole($superAdmin);

        // Graham — platform Admin
        $grahamEmail      = env('SEED_GRAHAM_EMAIL', 'graham@suresigncontracts.com');
        $grahamGenerated  = empty(env('SEED_GRAHAM_PASSWORD'));
        $grahamPassword   = env('SEED_GRAHAM_PASSWORD') ?: Str::random(20);

        $graham = User::firstOrCreate(
            ['email' => $grahamEmail],
            [
                'organization_id'       => null,
                'name'                  => 'Graham',
                'password'              => Hash::make($grahamPassword),
                'is_active'             => true,
                'job_title'             => 'Director',
                'must_change_password'  => true,
            ]
        );
        $graham->assignRole($adminRole);

        $this->command->info('✓ Seeded: roles, permissions, super admin');

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

        // Default platform email configuration. Idempotent — only fills in
        // fields that are still empty, so it never overwrites values an
        // admin has already configured via Settings → General/Email.
        $settings = SuresignSetting::instance();
        $settings->fill([
            'support_email'      => $settings->support_email      ?: env('SEED_SUPPORT_EMAIL', 'tech@suresigncontracts.com'),
            'email_sender_email' => $settings->email_sender_email  ?: env('SEED_EMAIL_SENDER_EMAIL', 'noreply@suresigncontracts.app'),
            'email_sender_name'  => $settings->email_sender_name   ?: env('SEED_EMAIL_SENDER_NAME', 'SureSign'),
            'email_reply_to'     => $settings->email_reply_to      ?: env('SEED_EMAIL_REPLY_TO', 'tech@suresigncontracts.com'),
            'admin_email'        => $settings->admin_email         ?: env('SEED_ADMIN_EMAIL', 'tech@suresigncontracts.com'),
            'email_subject_line' => $settings->email_subject_line  ?: env('SEED_EMAIL_SUBJECT_LINE', 'You have a new document from SureSign'),
        ]);
        // Operational default only — the Consultancy consultant is a
        // platform setting, resolved dynamically at runtime via
        // App\Services\Consultancy\ConsultancyConsultantResolver. This
        // seeder configures Graham's account as that default for local/
        // staging setup; no domain service ever hardcodes Graham directly.
        // Idempotent — never overwrites a value already configured via the
        // Admin Consultancy Settings page.
        if (!$settings->consultancy_consultant_user_id) {
            $settings->consultancy_consultant_user_id = $graham->id;
        }
        $settings->save();

        $this->call([
            PromptCategorySeeder::class,
            PromptTemplateSeeder::class,
            AppointmentTypeSeeder::class,
            ConsultancyServiceSeeder::class,
            PricingSeeder::class,
        ]);
    }
}
