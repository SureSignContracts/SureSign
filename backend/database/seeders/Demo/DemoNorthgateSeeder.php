<?php

namespace Database\Seeders\Demo;

use App\Models\Contract;
use App\Models\ContractProgrammeMilestone;
use App\Models\ContractRisk;
use App\Models\Document;
use App\Models\MeetingMinutes;
use App\Models\Organization;
use App\Models\PaymentApplication;
use App\Models\Project;
use App\Models\ProjectContact;
use App\Models\Rfi;
use App\Models\SiteDiary;
use App\Models\TradePackage;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\Demo\Data\DemoActivityLogger;
use Database\Seeders\Demo\Data\DemoCompanyProfile;
use Database\Seeders\Demo\Data\NorthgateStory as Story;
use Illuminate\Database\Seeder;

/**
 * Northgate Business Units — Phase 2: the "early construction" project in
 * the approved demo portfolio (Phase 4). Month 2 of 12 — one payment
 * application submitted and still under assessment (nothing certified
 * yet), most of the programme still ahead. Demonstrates active contract
 * administration from the very start of a build.
 *
 * Consolidated into one seeder class — see DemoColdfieldSeeder's class
 * comment for the rationale.
 */
class DemoNorthgateSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::where('slug', DemoCompanyProfile::ORGANIZATION['slug'])->firstOrFail();
        $daniel = User::where('email', 'daniel.okafor@haldengroveconstruction.com')->firstOrFail();
        $sarah = User::where('email', 'sarah.blythe@haldengroveconstruction.com')->firstOrFail();
        $james = User::where('email', 'james.ridley@haldengroveconstruction.com')->firstOrFail();

        $project = $this->seedProject($organization, $daniel);
        $contract = $this->seedContract($project, $daniel);
        $this->seedTradePackages($project, $daniel);
        $this->seedProgramme($project, $contract);
        $this->seedRisks($project, $contract);
        $this->seedPaymentApplications($project, $contract, $daniel);
        $this->seedRfis($project, $sarah);
        $this->seedMeetings($project, $daniel);
        $this->seedSiteDiaries($project, $sarah);
        $this->seedDocuments($project, $contract, $james);

        $this->command?->info("✓ Demo project: {$project->name} (id {$project->id}) — early construction.");
    }

    private function seedProject(Organization $organization, User $daniel): Project
    {
        $project = Project::updateOrCreate(
            ['organization_id' => $organization->id, 'code' => Story::PROJECT['code']],
            array_merge(Story::PROJECT, ['created_by' => $daniel->id])
        );

        foreach (Story::PROJECT_TEAM as $member) {
            $user = User::where('email', $member['email'])->first();
            if ($user) {
                $project->users()->syncWithoutDetaching([$user->id => ['role' => $member['role']]]);
            }
        }

        foreach (Story::PROJECT_CONTACTS as $contact) {
            ProjectContact::updateOrCreate(
                ['project_id' => $project->id, 'name' => $contact['name'], 'company' => $contact['company']],
                array_merge(['project_id' => $project->id], $contact)
            );
        }

        if ($project->wasRecentlyCreated) {
            DemoActivityLogger::log($project, $daniel, 'project.created', "Project created: {$project->name}", Story::PROJECT['start_date']);
        }

        return $project;
    }

    private function seedContract(Project $project, User $daniel): Contract
    {
        $contract = Contract::updateOrCreate(
            ['project_id' => $project->id, 'reference_number' => Story::CONTRACT['reference_number']],
            array_merge(Story::CONTRACT, [
                'project_id' => $project->id,
                'organization_id' => $project->organization_id,
                'created_by' => $daniel->id,
            ])
        );

        if ($contract->wasRecentlyCreated) {
            DemoActivityLogger::log($project, $daniel, 'contract.executed', 'Main contract executed', Story::CONTRACT['execution_date'], null, $contract);
        }

        return $contract;
    }

    private function seedTradePackages(Project $project, User $daniel): void
    {
        foreach (Story::TRADE_PACKAGES as $data) {
            $tradePackage = TradePackage::updateOrCreate(
                ['project_id' => $project->id, 'package_code' => $data['code']],
                [
                    'organization_id' => $project->organization_id,
                    'project_id' => $project->id,
                    'name' => $data['name'],
                    'slug' => TradePackage::makeSlug($data['name'], $project->id),
                    'package_code' => $data['code'],
                    'contractor_name' => $data['contractor_name'],
                    'status' => $data['status'],
                    'contract_value' => $data['contract_value'],
                    'retention_percentage' => Story::CONTRACT['retention_percentage'],
                    'payment_frequency' => 'monthly',
                    'award_date' => $data['award_date'],
                    'commencement_date' => $data['commencement_date'],
                    'completion_date' => $data['completion_date'],
                    'created_by' => $daniel->id,
                ]
            );

            if ($tradePackage->wasRecentlyCreated) {
                $tradePackage->createStandardFolders();
                if ($data['award_date']) {
                    DemoActivityLogger::log($project, $daniel, 'trade_package.awarded', "Trade package awarded: {$data['name']}", $data['award_date'], null, $tradePackage);
                }
            }
        }
    }

    private function seedProgramme(Project $project, Contract $contract): void
    {
        foreach (Story::PROGRAMME_MILESTONES as $index => $data) {
            ContractProgrammeMilestone::updateOrCreate(
                ['contract_id' => $contract->id, 'name' => $data['name']],
                [
                    'contract_id' => $contract->id,
                    'project_id' => $project->id,
                    'name' => $data['name'],
                    'milestone_type' => $data['milestone_type'],
                    'planned_date' => $data['planned_date'],
                    'forecast_date' => $data['planned_date'],
                    'actual_date' => $data['actual_date'],
                    'status' => $data['status'],
                    'responsible_party' => 'contractor',
                    'sort_order' => $index + 1,
                ]
            );
        }
    }

    private function seedRisks(Project $project, Contract $contract): void
    {
        foreach (Story::RISKS as $data) {
            ContractRisk::updateOrCreate(
                ['organization_id' => $project->organization_id, 'title' => $data['title']],
                [
                    'organization_id' => $project->organization_id,
                    'project_id' => $project->id,
                    'contract_id' => $contract->id,
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
    }

    /**
     * First payment cycle — deliberately 'submitted', not certified: no
     * certified_amount/certified_at/paid_at exists yet, unlike every other
     * project's payment application seeding. This is the one place this
     * project's data shape genuinely differs from its siblings.
     */
    private function seedPaymentApplications(Project $project, Contract $contract, User $daniel): void
    {
        foreach (Story::PAYMENT_APPLICATIONS as $data) {
            $applicationDate = Carbon::parse($data['application_date']);
            $retention = round($data['gross_valuation'] * ($contract->retention_percentage / 100), 2);
            $netValuation = round($data['gross_valuation'] - $retention, 2);

            $application = PaymentApplication::updateOrCreate(
                ['contract_id' => $contract->id, 'application_number' => $data['application_number']],
                [
                    'contract_id' => $contract->id,
                    'project_id' => $project->id,
                    'organization_id' => $project->organization_id,
                    'created_by' => $daniel->id,
                    'application_number' => $data['application_number'],
                    'reference' => "PA-{$data['application_number']}",
                    'application_date' => $data['application_date'],
                    'valuation_period_start' => $data['valuation_period_start'],
                    'valuation_period_end' => $data['valuation_period_end'],
                    'due_date' => $applicationDate->copy()->addDays($contract->due_date_offset_days)->toDateString(),
                    'final_date_for_payment' => $applicationDate->copy()->addDays($contract->final_date_offset_days)->toDateString(),
                    'payment_notice_deadline' => $applicationDate->copy()->addDays($contract->payment_notice_offset_days)->toDateString(),
                    'pay_less_notice_deadline' => $applicationDate->copy()->addDays($contract->pay_less_notice_offset_days)->toDateString(),
                    'gross_valuation' => $data['gross_valuation'],
                    'less_retention' => $retention,
                    'less_previous_payments' => 0,
                    'previous_certified_value' => 0,
                    'amount_due' => $netValuation,
                    'status' => $data['status'],
                    'notes' => $data['notes'],
                    'submitted_at' => $applicationDate,
                    'submitted_by' => $daniel->id,
                ]
            );

            if ($application->wasRecentlyCreated) {
                DemoActivityLogger::log($project, $daniel, 'payment_application.submitted', "Payment Application {$data['application_number']} submitted", $data['application_date'], $data['notes'], $application);
            }
        }
    }

    private function seedRfis(Project $project, User $sarah): void
    {
        foreach (Story::RFIS as $data) {
            $rfi = Rfi::updateOrCreate(
                ['project_id' => $project->id, 'rfi_number' => $data['rfi_number']],
                array_merge($data, [
                    'project_id' => $project->id,
                    'organization_id' => $project->organization_id,
                    'created_by' => $sarah->id,
                ])
            );

            if ($rfi->wasRecentlyCreated) {
                DemoActivityLogger::log($project, $sarah, 'rfi.raised', "RFI {$data['rfi_number']} raised: {$data['subject']}", $data['raised_date'], null, $rfi);
            }
        }
    }

    private function seedMeetings(Project $project, User $daniel): void
    {
        foreach (Story::MEETINGS as $data) {
            MeetingMinutes::updateOrCreate(
                ['project_id' => $project->id, 'meeting_number' => $data['meeting_number']],
                [
                    'project_id' => $project->id,
                    'organization_id' => $project->organization_id,
                    'created_by' => $daniel->id,
                    'meeting_number' => $data['meeting_number'],
                    'title' => $data['title'],
                    'type' => $data['type'],
                    'meeting_date' => $data['meeting_date'],
                    'location' => 'Northgate Business Park site office',
                    'status' => 'issued',
                ]
            );
        }
    }

    private function seedSiteDiaries(Project $project, User $sarah): void
    {
        foreach (Story::SITE_DIARIES as $data) {
            SiteDiary::updateOrCreate(
                ['project_id' => $project->id, 'diary_date' => $data['diary_date']],
                array_merge($data, [
                    'project_id' => $project->id,
                    'organization_id' => $project->organization_id,
                    'created_by' => $sarah->id,
                    'status' => 'approved',
                ])
            );
        }
    }

    private function seedDocuments(Project $project, Contract $contract, User $james): void
    {
        $documents = [
            ['title' => 'Northgate Business Units — Phase 2: Main Contract (Executed)', 'type' => 'contract', 'category' => 'Contracts', 'reference_number' => 'HG-NBU-P2-001', 'documentable' => $contract],
            ['title' => 'GA-NBU-101 Rev A — Site Layout & Unit Footprints', 'type' => 'other', 'category' => 'Drawings', 'reference_number' => 'GA-NBU-101-A', 'documentable' => null],
        ];

        foreach ($documents as $data) {
            Document::updateOrCreate(
                ['project_id' => $project->id, 'reference_number' => $data['reference_number']],
                [
                    'project_id' => $project->id,
                    'organization_id' => $project->organization_id,
                    'created_by' => $james->id,
                    'title' => $data['title'],
                    'type' => $data['type'],
                    'category' => $data['category'],
                    'reference_number' => $data['reference_number'],
                    'status' => 'issued',
                    'file_name' => str($data['reference_number'])->lower() . '.pdf',
                    'file_path' => "projects/{$project->id}/documents/" . str($data['reference_number'])->lower() . '.pdf',
                    'mime_type' => 'application/pdf',
                    'documentable_type' => $data['documentable'] ? get_class($data['documentable']) : null,
                    'documentable_id' => $data['documentable']?->id,
                    'ai_generated' => false,
                ]
            );
        }
    }
}
