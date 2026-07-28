<?php

namespace Database\Seeders\Demo;

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\Demo\Data\DemoCompanyProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Creates the six Halden Grove personas as real Client-role (org-scoped)
 * users, per the approved demo environment blueprint. Depends on
 * DemoOrganizationSeeder and DemoRoleSeeder having already run.
 *
 * Passwords are never hard-coded: each new account gets a random generated
 * password, printed once to the console at creation time only, with
 * must_change_password forced true — mirroring the existing convention in
 * database/seeders/DatabaseSeeder.php for the platform Super Admin/Admin
 * accounts. Re-running demo:seed against already-created users never
 * reprints or resets a password.
 */
class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::where('slug', DemoCompanyProfile::ORGANIZATION['slug'])->firstOrFail();

        foreach (DemoCompanyProfile::USERS as $persona) {
            $generatedPassword = Str::random(20);

            $user = User::firstOrCreate(
                ['email' => $persona['email']],
                [
                    'organization_id' => $organization->id,
                    'name' => $persona['name'],
                    'first_name' => $persona['first_name'],
                    'last_name' => $persona['last_name'],
                    'job_title' => $persona['job_title'],
                    'password' => Hash::make($generatedPassword),
                    'is_active' => true,
                    'must_change_password' => true,
                    'email_verified_at' => now(),
                ]
            );

            if (! $user->hasRole('Client')) {
                $user->assignRole('Client');
            }

            if ($user->wasRecentlyCreated) {
                $this->command?->info("  Created {$persona['email']} ({$persona['job_title']}) with a temporary password: {$generatedPassword}");
            }
        }

        $this->command?->info('✓ Demo users: ' . count(DemoCompanyProfile::USERS) . ' personas ready. Primary demo account: ' . DemoCompanyProfile::primaryDemoUserEmail());
    }
}
