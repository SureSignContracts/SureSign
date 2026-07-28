<?php

namespace Database\Seeders\Demo;

use App\Models\BrandingSetting;
use App\Models\Organization;
use Database\Seeders\Demo\Data\DemoCompanyProfile;
use Illuminate\Database\Seeder;

/**
 * Creates the Halden Grove Construction Ltd. organization and its branding
 * profile — Phase 1 of the approved demo environment blueprint. No projects,
 * contracts, or activity yet; those are later phases.
 *
 * Idempotent via the 'slug' anchor in DemoCompanyProfile — re-running
 * demo:seed updates the same organization/branding rows rather than
 * duplicating them.
 */
class DemoOrganizationSeeder extends Seeder
{
    public function run(): void
    {
        $profile = DemoCompanyProfile::ORGANIZATION;

        $organization = Organization::updateOrCreate(
            ['slug' => $profile['slug']],
            $profile
        );

        BrandingSetting::updateOrCreate(
            ['organization_id' => $organization->id],
            DemoCompanyProfile::BRANDING
        );

        $this->command?->info("✓ Demo organization: {$organization->name} (id {$organization->id})");
    }
}
