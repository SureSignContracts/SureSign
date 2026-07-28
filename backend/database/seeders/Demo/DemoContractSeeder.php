<?php

namespace Database\Seeders\Demo;

use App\Models\Contract;
use App\Models\ContractAiAnalysis;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\Demo\Data\DemoActivityLogger;
use Database\Seeders\Demo\Data\DemoCompanyProfile;
use Database\Seeders\Demo\Data\RiversideWharfStory;
use Illuminate\Database\Seeder;

/**
 * Creates the Riverside Wharf main contract and a completed, confirmed
 * Contract AI Analysis against it.
 *
 * The AI analysis is deliberately NOT produced by a live call to
 * ClaudeAiProvider — seeding must not depend on a configured Anthropic API
 * key or incur real API cost, and CLAUDE.md is explicit that no new AI
 * integrations should be added. Instead this inserts a `confirmed_data_json`
 * row shaped like genuine extraction output (the same fields
 * ContractAnalysisService would populate), so the AI Analysis review screen
 * renders exactly as it would for a real analysis — it just skips the
 * network call. This is a documented compromise, not a hidden one.
 */
class DemoContractSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::where('slug', DemoCompanyProfile::ORGANIZATION['slug'])->firstOrFail();
        $project = Project::where('organization_id', $organization->id)
            ->where('code', RiversideWharfStory::PROJECT['code'])
            ->firstOrFail();
        $daniel = User::where('email', 'daniel.okafor@haldengroveconstruction.com')->firstOrFail();

        $contract = Contract::updateOrCreate(
            ['project_id' => $project->id, 'reference_number' => RiversideWharfStory::CONTRACT['reference_number']],
            array_merge(RiversideWharfStory::CONTRACT, [
                'project_id' => $project->id,
                'organization_id' => $organization->id,
                'created_by' => $daniel->id,
            ])
        );

        $analysisData = RiversideWharfStory::CONTRACT_AI_ANALYSIS;

        $confirmedData = [
            'parties' => [
                'employer' => RiversideWharfStory::CONTRACT['employer_name'],
                'contractor' => RiversideWharfStory::CONTRACT['principal_contractor'],
                'employers_agent' => RiversideWharfStory::CONTRACT['qs_name'],
            ],
            'key_dates' => [
                'commencement_date' => RiversideWharfStory::CONTRACT['commencement_date'],
                'completion_date' => RiversideWharfStory::CONTRACT['completion_date'],
                'defects_liability_period' => RiversideWharfStory::CONTRACT['defects_liability_period'],
            ],
            'payment_terms' => [
                'frequency' => RiversideWharfStory::CONTRACT['payment_frequency'],
                'retention_percentage' => RiversideWharfStory::CONTRACT['retention_percentage'],
                'retention_release' => [
                    RiversideWharfStory::CONTRACT['retention_half1_release'],
                    RiversideWharfStory::CONTRACT['retention_half2_release'],
                ],
                'liquidated_damages' => RiversideWharfStory::CONTRACT['liquidated_damages'],
            ],
            'contract_sum' => RiversideWharfStory::CONTRACT['contract_sum'],
        ];

        $analysis = ContractAiAnalysis::updateOrCreate(
            ['contract_id' => $contract->id],
            array_merge($analysisData, [
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'status' => 'confirmed',
                'confirmed_data_json' => $confirmedData,
                'started_at' => '2025-10-07 09:14:00',
                'completed_at' => '2025-10-07 09:14:52',
                'created_by' => $daniel->id,
            ])
        );

        if ($analysis->wasRecentlyCreated) {
            DemoActivityLogger::log(
                $project,
                $daniel,
                'contract.ai_analysis_confirmed',
                'Contract AI analysis confirmed',
                '2025-10-07',
                'Extracted payment terms, key dates, and retention rules confirmed for use in statutory date calculations.',
                $contract
            );

            DemoActivityLogger::log(
                $project,
                $daniel,
                'contract.executed',
                'Main contract executed',
                RiversideWharfStory::CONTRACT['execution_date'],
                'JCT Design and Build Contract 2016 executed with Riverside Wharf Developments LLP.',
                $contract
            );
        }

        $this->command?->info("✓ Demo contract: {$contract->title} (id {$contract->id})");
    }
}
