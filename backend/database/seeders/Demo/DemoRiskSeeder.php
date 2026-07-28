<?php

namespace Database\Seeders\Demo;

use App\Models\Contract;
use App\Models\ContractRisk;
use App\Models\Organization;
use App\Models\Project;
use App\Models\TradePackage;
use Database\Seeders\Demo\Data\DemoCompanyProfile;
use Database\Seeders\Demo\Data\RiversideWharfStory;
use Illuminate\Database\Seeder;

/**
 * Creates the six Riverside Wharf risks — a genuine spread across open,
 * mitigated, and closed, some tied to the main contract and some to a
 * specific trade package (contract_risks allows exactly one of the two;
 * see internal-docs/demo-environment/index.md for why is_ai_generated is
 * forced false here — these are authored, not AI-extracted).
 */
class DemoRiskSeeder extends Seeder
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

        foreach (RiversideWharfStory::RISKS as $data) {
            $tradePackageId = null;
            $contractId = $contract->id;

            if ($data['trade_package_code']) {
                $tradePackageId = TradePackage::where('project_id', $project->id)
                    ->where('package_code', $data['trade_package_code'])
                    ->value('id');
                $contractId = null;
            }

            ContractRisk::updateOrCreate(
                ['organization_id' => $organization->id, 'title' => $data['title']],
                [
                    'organization_id' => $organization->id,
                    'project_id' => $project->id,
                    'contract_id' => $contractId,
                    'trade_package_id' => $tradePackageId,
                    'title' => $data['title'],
                    'description' => $data['description'],
                    'severity' => $data['severity'],
                    'probability' => $data['probability'],
                    'category' => $data['category'],
                    'urgency' => $data['urgency'],
                    'mitigation' => $data['mitigation'],
                    'status' => $data['status'],
                    'is_ai_generated' => false,
                ]
            );
        }

        $this->command?->info('✓ Demo risks: ' . count(RiversideWharfStory::RISKS) . ' risks ready.');
    }
}
