<?php

namespace Database\Seeders\Demo;

use App\Models\Contract;
use App\Models\ContractProgrammeMilestone;
use App\Models\Organization;
use App\Models\Project;
use Database\Seeders\Demo\Data\DemoCompanyProfile;
use Database\Seeders\Demo\Data\RiversideWharfStory;
use Illuminate\Database\Seeder;

/**
 * Creates the contract-level programme milestones for Riverside Wharf —
 * the top-level programme a client or Employer's Agent would see, spanning
 * completed, in-progress, and not-yet-started milestones.
 */
class DemoProgrammeSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::where('slug', DemoCompanyProfile::ORGANIZATION['slug'])->firstOrFail();
        $project = Project::where('organization_id', $organization->id)
            ->where('code', RiversideWharfStory::PROJECT['code'])
            ->firstOrFail();
        $contract = Contract::where('project_id', $project->id)
            ->where('reference_number', RiversideWharfStory::CONTRACT['reference_number'])
            ->firstOrFail();

        foreach (RiversideWharfStory::PROGRAMME_MILESTONES as $index => $data) {
            ContractProgrammeMilestone::updateOrCreate(
                ['contract_id' => $contract->id, 'name' => $data['name']],
                [
                    'contract_id' => $contract->id,
                    'project_id' => $project->id,
                    'name' => $data['name'],
                    'milestone_type' => $data['milestone_type'],
                    'planned_date' => $data['planned_date'],
                    'forecast_date' => $data['actual_date'] ?? $data['planned_date'],
                    'actual_date' => $data['actual_date'],
                    'status' => $data['status'],
                    'responsible_party' => 'contractor',
                    'sort_order' => $index + 1,
                ]
            );
        }

        $this->command?->info('✓ Demo programme: ' . count(RiversideWharfStory::PROGRAMME_MILESTONES) . ' milestones ready.');
    }
}
