<?php

namespace Database\Seeders\Demo;

use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectContact;
use App\Models\User;
use Database\Seeders\Demo\Data\DemoActivityLogger;
use Database\Seeders\Demo\Data\DemoCompanyProfile;
use Database\Seeders\Demo\Data\RiversideWharfStory;
use Illuminate\Database\Seeder;

/**
 * Creates the Riverside Wharf — Block C Residential flagship project: the
 * project record itself, its project team (project_users), and its two
 * external contacts (Employer's Agent and Employer). Phase 2 of the
 * approved demo environment blueprint — see
 * internal-docs/demo-environment/index.md.
 *
 * Idempotent on the project's `code` (unique per the story, not enforced
 * by a DB constraint but treated as the seeder's anchor) within Halden
 * Grove's organization.
 */
class DemoProjectSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::where('slug', DemoCompanyProfile::ORGANIZATION['slug'])->firstOrFail();
        $priya = User::where('email', 'priya.chandra@haldengroveconstruction.com')->firstOrFail();

        $project = Project::updateOrCreate(
            ['organization_id' => $organization->id, 'code' => RiversideWharfStory::PROJECT['code']],
            array_merge(RiversideWharfStory::PROJECT, ['created_by' => $priya->id])
        );

        foreach (RiversideWharfStory::PROJECT_TEAM as $member) {
            $user = User::where('email', $member['email'])->first();
            if ($user) {
                $project->users()->syncWithoutDetaching([$user->id => ['role' => $member['role']]]);
            }
        }

        foreach (RiversideWharfStory::PROJECT_CONTACTS as $contact) {
            ProjectContact::updateOrCreate(
                ['project_id' => $project->id, 'name' => $contact['name'], 'company' => $contact['company']],
                array_merge(['project_id' => $project->id], $contact)
            );
        }

        if ($project->wasRecentlyCreated) {
            DemoActivityLogger::log(
                $project,
                $priya,
                'project.created',
                'Project created: Riverside Wharf — Block C Residential',
                RiversideWharfStory::PROJECT['start_date'],
                'Project set up in SureSign ahead of site possession.'
            );
        }

        $this->command?->info("✓ Demo project: {$project->name} (id {$project->id})");
    }
}
