<?php

namespace Database\Seeders\Demo;

use App\Models\Organization;
use App\Models\Project;
use App\Models\TradePackage;
use App\Models\User;
use Database\Seeders\Demo\Data\DemoActivityLogger;
use Database\Seeders\Demo\Data\DemoCompanyProfile;
use Database\Seeders\Demo\Data\RiversideWharfStory;
use Illuminate\Database\Seeder;

/**
 * Creates the ten trade packages for Riverside Wharf, spanning every
 * lifecycle stage from 'tendering' through to 'completed' — this is what
 * makes the project read as genuinely 9 months into an 18-month programme
 * rather than a snapshot where everything is either finished or untouched.
 */
class DemoTradePackageSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::where('slug', DemoCompanyProfile::ORGANIZATION['slug'])->firstOrFail();
        $project = Project::where('organization_id', $organization->id)
            ->where('code', RiversideWharfStory::PROJECT['code'])
            ->firstOrFail();
        $daniel = User::where('email', 'daniel.okafor@haldengroveconstruction.com')->firstOrFail();

        foreach (RiversideWharfStory::TRADE_PACKAGES as $data) {
            $tradePackage = TradePackage::updateOrCreate(
                ['project_id' => $project->id, 'package_code' => $data['code']],
                [
                    'organization_id' => $organization->id,
                    'project_id' => $project->id,
                    'name' => $data['name'],
                    'slug' => TradePackage::makeSlug($data['name'], $project->id),
                    'package_code' => $data['code'],
                    'contractor_name' => $data['contractor_name'],
                    'status' => $data['status'],
                    'contract_value' => $data['contract_value'],
                    'retention_percentage' => $data['retention_percentage'],
                    'payment_frequency' => $data['payment_frequency'],
                    'award_date' => $data['award_date'],
                    'commencement_date' => $data['commencement_date'],
                    // For completed packages this is the actual completion date; for
                    // active/awarded/tendering packages it's the programmed target —
                    // trade_packages has one completion_date column either way, and
                    // `status` is what distinguishes "achieved" from "planned".
                    'completion_date' => $data['completion_date'],
                    'created_by' => $daniel->id,
                ]
            );

            if ($tradePackage->wasRecentlyCreated) {
                $tradePackage->createStandardFolders();

                if ($data['award_date']) {
                    DemoActivityLogger::log(
                        $project,
                        $daniel,
                        'trade_package.awarded',
                        "Trade package awarded: {$data['name']}",
                        $data['award_date'],
                        $data['contractor_name'] ? "Awarded to {$data['contractor_name']}." : null,
                        $tradePackage
                    );
                }

                if ($data['status'] === 'completed') {
                    DemoActivityLogger::log(
                        $project,
                        $daniel,
                        'trade_package.completed',
                        "Trade package completed: {$data['name']}",
                        $data['completion_date'],
                        null,
                        $tradePackage
                    );
                }
            }
        }

        $this->command?->info('✓ Demo trade packages: ' . count(RiversideWharfStory::TRADE_PACKAGES) . ' packages ready.');
    }
}
